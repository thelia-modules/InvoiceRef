<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace InvoiceRef\Tests;

use InvoiceRef\Service\InvoiceRefSequence;
use Propel\Runtime\Connection\ConnectionWrapper;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The status change dispatched inside a transaction of its caller, as the order API processor does: whatever happens
 * to the numbering, the caller commits its transaction and the new status is recorded. The data is committed, so
 * this suite runs outside the transaction of the test case, on a test database of its own (name ending with `_test`).
 */
final class OuterTransactionTest extends IntegrationTestCase
{
    private const FAILING_INVOICE_REF = 'FAIL-1';

    protected bool $useTransaction = false;

    private ?Order $order = null;

    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();

        $connectedDatabase = $this->getPropelConnection()->query('SELECT DATABASE()')->fetchColumn();
        if ($connectedDatabase !== $databaseName) {
            self::fail(\sprintf('Connected to "%s" instead of "%s": rebuild var/propel/test.', (string) $connectedDatabase, $databaseName));
        }

        ConfigQuery::resetCache();
        ConfigQuery::write('invoice_ref_auto', '0');
        ConfigQuery::write(InvoiceRefSequence::CONFIG_NAME, (string) random_int(100000000, 900000000), true, true);
        ConfigQuery::create()->filterByName('invoiceRefStatuses')->delete();
        ConfigQuery::resetCache();

        $this->order = $this->createFixtureFactory()->order();
    }

    protected function tearDown(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec('DROP TRIGGER IF EXISTS `invoiceref_test_failing_update`');

        // Committed: removed by hand, the fixture factory would otherwise meet its own unique values on the next run.
        if (null !== $this->order) {
            $connection->exec('DELETE FROM `order` WHERE `id` = '.$this->order->getId());
            $connection->exec('DELETE FROM `cart` WHERE `id` = '.$this->order->getCartId());
            $connection->exec('DELETE FROM `customer` WHERE `id` = '.$this->order->getCustomerId());
            $this->order = null;
        }

        parent::tearDown();
    }

    public function testTheOrderIsNumberedInsideTheTransactionOfTheCaller(): void
    {
        $counter = $this->counter();

        $this->changeStatusInsideATransaction(OrderStatus::CODE_PAID);

        self::assertSame([OrderStatus::CODE_PAID, $counter], $this->recordedStatusAndInvoiceRef());
    }

    public function testAMissingCounterDoesNotCancelTheStatusChangeOfTheCaller(): void
    {
        ConfigQuery::create()->filterByName(InvoiceRefSequence::CONFIG_NAME)->delete();
        ConfigQuery::resetCache();

        $this->changeStatusInsideATransaction(OrderStatus::CODE_PAID);

        self::assertSame([OrderStatus::CODE_PAID, null], $this->recordedStatusAndInvoiceRef());
    }

    public function testAFailureWhileWritingTheNumberOnlyUndoesTheNumbering(): void
    {
        // Passes the checks made before the transaction, then fails on the write of the order.
        ConfigQuery::write(InvoiceRefSequence::CONFIG_NAME, self::FAILING_INVOICE_REF, true, true);
        $this->getPropelConnection()->exec(
            'CREATE TRIGGER `invoiceref_test_failing_update` BEFORE UPDATE ON `order` FOR EACH ROW '
            .'BEGIN IF NEW.`invoice_ref` = \''.self::FAILING_INVOICE_REF.'\' THEN '
            .'SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'refused by the test\'; END IF; END'
        );

        $this->changeStatusInsideATransaction(OrderStatus::CODE_PAID);

        self::assertSame(
            [OrderStatus::CODE_PAID, null, self::FAILING_INVOICE_REF],
            [...$this->recordedStatusAndInvoiceRef(), $this->counter()],
        );
    }

    private function changeStatusInsideATransaction(string $statusCode): void
    {
        self::assertNotNull($this->order);
        $status = OrderStatusQuery::create()->findOneByCode($statusCode)
            ?? throw new \RuntimeException("Order status '$statusCode' is missing.");

        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $event = new OrderEvent($this->order);
        $event->setStatus($status->getId());
        $event->forceStatusTransition();

        $connection = $this->getPropelConnection();
        $connection->beginTransaction();

        try {
            $dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection instanceof ConnectionWrapper && $connection->inTransaction()) {
                $connection->forceRollBack();
            }

            throw $throwable;
        }
    }

    /**
     * @return array{?string, ?string}
     */
    private function recordedStatusAndInvoiceRef(): array
    {
        self::assertNotNull($this->order);
        $statement = $this->getPropelConnection()->prepare(
            'SELECT `order_status`.`code`, `order`.`invoice_ref` FROM `order` JOIN `order_status` ON `order_status`.`id` = `order`.`status_id` WHERE `order`.`id` = :id'
        );
        $statement->bindValue(':id', $this->order->getId(), \PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch(\PDO::FETCH_NUM);
        self::assertIsArray($row);

        return [$row[0], $row[1]];
    }

    private function counter(): ?string
    {
        $statement = $this->getPropelConnection()->prepare('SELECT `value` FROM `config` WHERE `name` = :name');
        $statement->bindValue(':name', InvoiceRefSequence::CONFIG_NAME, \PDO::PARAM_STR);
        $statement->execute();

        $value = $statement->fetchColumn();

        return \is_string($value) ? $value : null;
    }
}
