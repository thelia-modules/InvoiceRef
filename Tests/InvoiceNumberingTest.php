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

use InvoiceRef\Command\AssignInvoiceRefCommand;
use InvoiceRef\Form\ConfigurationForm;
use InvoiceRef\InvoiceRef;
use InvoiceRef\Service\InvoiceRefSequence;
use InvoiceRef\Service\NumberedStatuses;
use PHPUnit\Framework\Attributes\DataProvider;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Runs on a test database of its own, never on the shop's: the database name must end with `_test`.
 */
final class InvoiceNumberingTest extends IntegrationTestCase
{
    private FixtureFactory $fixtures;

    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();

        // The Propel configuration of the test environment is generated once, with the database of whoever built it.
        $connectedDatabase = $this->getPropelConnection()->query('SELECT DATABASE()')->fetchColumn();
        if ($connectedDatabase !== $databaseName) {
            self::fail(\sprintf('Connected to "%s" instead of "%s": rebuild var/propel/test.', (string) $connectedDatabase, $databaseName));
        }

        // The static configuration cache outlives the rollback of the previous test.
        ConfigQuery::resetCache();

        $this->fixtures = $this->createFixtureFactory();

        // The core numbering (priority 64) is on in a fresh install: off here, so that only this module numbers.
        ConfigQuery::write('invoice_ref_auto', '0');
        ConfigQuery::write(InvoiceRefSequence::CONFIG_NAME, '1000', true, true);
        ConfigQuery::create()->filterByName(NumberedStatuses::CONFIG_NAME)->delete();
        ConfigQuery::resetCache();
    }

    public function testThePaidStatusGivesTheNextNumberAndMovesTheCounter(): void
    {
        $order = $this->fixtures->order();

        $this->changeStatus($order, OrderStatus::CODE_PAID);

        self::assertSame(['1000', '1001'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    public function testOnlyThePaidStatusNumbersWithoutTheSetting(): void
    {
        $order = $this->fixtures->order();

        $this->changeStatus($order, OrderStatus::CODE_REFUNDED);

        self::assertSame([null, '1000'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    public function testACustomStatusEquivalentToPaidNumbersWithoutTheSetting(): void
    {
        $status = $this->fixtures->orderStatus(['equivalentCode' => OrderStatus::CODE_PAID]);
        $status->setProtectedStatus(0)->save();
        $order = $this->fixtures->order();

        $this->changeStatus($order, $status->getCode());

        self::assertSame(['1000', '1001'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function configuredStatuses(): iterable
    {
        yield 'paid' => ['paid'];
        yield 'refunded' => ['refunded'];
        yield 'exchange' => ['exchange'];
        yield 'Return' => ['Return'];
    }

    #[DataProvider('configuredStatuses')]
    public function testEachConfiguredStatusGivesTheNextNumber(string $statusCode): void
    {
        $this->ensureStatus('exchange');
        $this->ensureStatus('Return');
        (new NumberedStatuses())->save(['paid', 'refunded', 'exchange', 'Return']);
        $order = $this->fixtures->order();

        $this->changeStatus($order, $statusCode);

        self::assertSame(['1000', '1001'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    public function testAStatusLeftOutOfTheSettingDoesNotNumber(): void
    {
        (new NumberedStatuses())->save(['refunded']);
        $order = $this->fixtures->order();

        $this->changeStatus($order, OrderStatus::CODE_PAID);

        self::assertSame([null, '1000'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    public function testAnOrderAlreadyNumberedKeepsItsNumber(): void
    {
        (new NumberedStatuses())->save(['paid', 'refunded']);
        $order = $this->fixtures->order();
        $this->changeStatus($order, OrderStatus::CODE_PAID);

        $this->changeStatus($order, OrderStatus::CODE_REFUNDED);

        self::assertSame(['1000', '1001'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    public function testAnOrderNumberedMeanwhileByAnotherProcessDoesNotConsumeASecondNumber(): void
    {
        $order = $this->fixtures->order();
        // Another process (a retried payment notification) numbered the order after this one loaded it.
        $this->execute('UPDATE `order` SET `invoice_ref` = \'999\' WHERE `id` = '.$order->getId());

        $this->changeStatus($order, OrderStatus::CODE_PAID);

        self::assertSame(['999', '1000'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    public function testANumberAlreadyCarriedByAnOrderIsSkipped(): void
    {
        $this->orderWithInvoiceRef('1000');
        $this->orderWithInvoiceRef('1001');
        $order = $this->fixtures->order();

        $this->changeStatus($order, OrderStatus::CODE_PAID);

        self::assertSame(['1002', '1003'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function referenceFormats(): iterable
    {
        yield 'numeric, as the module always did' => ['0099', '0099', '100'];
        yield 'prefixed number keeps its width' => ['FA-0099', 'FA-0099', 'FA-0100'];
        yield 'alphanumeric' => ['AZ', 'AZ', 'BA'];
    }

    #[DataProvider('referenceFormats')]
    public function testTheCounterIsIncrementedAfterItsFormat(string $counter, string $expectedInvoiceRef, string $expectedCounter): void
    {
        ConfigQuery::write(InvoiceRefSequence::CONFIG_NAME, $counter, true, true);
        $order = $this->fixtures->order();

        $this->changeStatus($order, OrderStatus::CODE_PAID);

        self::assertSame([$expectedInvoiceRef, $expectedCounter], [$this->invoiceRefOf($order), $this->counter()]);
    }

    public function testAMissingCounterLeavesThePaidOrderUnnumberedWithoutFailingTheStatusChange(): void
    {
        ConfigQuery::create()->filterByName(InvoiceRefSequence::CONFIG_NAME)->delete();
        ConfigQuery::resetCache();
        $order = $this->fixtures->order();

        $this->changeStatus($order, OrderStatus::CODE_PAID);

        $order = OrderQuery::create()->findPk($order->getId());
        self::assertSame([OrderStatus::CODE_PAID, null], [$order?->getOrderStatus()->getCode(), $order?->getInvoiceRef()]);
    }

    public function testANumberIsNeverDrawnOutsideATransaction(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('inTransaction')->willReturn(false);

        $this->expectException(\LogicException::class);

        (new InvoiceRefSequence())->next($connection);
    }

    public function testActivationStartsTheCounterAfterTheHighestNumericInvoiceRef(): void
    {
        ConfigQuery::create()->filterByName(InvoiceRefSequence::CONFIG_NAME)->delete();
        ConfigQuery::resetCache();
        // Above the numbers drawn by ConcurrentNumberingTest, which commits them. Sorted as text, 999999999 would win.
        $this->orderWithInvoiceRef('999999999');
        $this->orderWithInvoiceRef('1000000000');
        $this->orderWithInvoiceRef('2026-000001');

        (new InvoiceRef())->postActivation();

        self::assertSame('1000000001', $this->counter());
    }

    public function testANumberedStatusOtherThanPaidDatesTheInvoice(): void
    {
        (new NumberedStatuses())->save(['refunded']);
        $order = $this->fixtures->order();

        $this->changeStatus($order, OrderStatus::CODE_REFUNDED);

        $statement = $this->getPropelConnection()->prepare('SELECT `invoice_ref`, IF(`invoice_date` IS NULL, "no", "yes") FROM `order` WHERE `id` = :id');
        $statement->bindValue(':id', $order->getId(), \PDO::PARAM_INT);
        $statement->execute();

        self::assertSame(['1000', 'yes'], $statement->fetch(\PDO::FETCH_NUM));
    }

    public function testACounterThatCannotBeIncrementedLeavesTheOrderAndTheCounterUntouched(): void
    {
        ConfigQuery::write(InvoiceRefSequence::CONFIG_NAME, 'FA-', true, true);
        $order = $this->fixtures->order();

        $this->changeStatus($order, OrderStatus::CODE_PAID);

        self::assertSame([null, 'FA-'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function counterValues(): iterable
    {
        yield 'digits' => ['1000', true];
        yield 'prefixed number' => ['FA-0099', true];
        yield 'letters and digits' => ['AZ', true];
        yield 'prefix only' => ['FA-', false];
        yield 'blank' => ['  ', false];
    }

    #[DataProvider('counterValues')]
    public function testTheConfigurationAcceptsOnlyACounterThatCanBeIncremented(string $value, bool $accepted): void
    {
        self::assertSame($accepted, InvoiceRefSequence::canIncrement($value));
    }

    public function testActivationTurnsTheCoreNumberingOff(): void
    {
        ConfigQuery::write('invoice_ref_auto', '1');

        (new InvoiceRef())->postActivation();

        self::assertSame('0', ConfigQuery::read('invoice_ref_auto'));
    }

    public function testTheCommandNumbersAnOrderLeftWithoutNumber(): void
    {
        $order = $this->fixtures->order();

        $tester = new CommandTester(new AssignInvoiceRefCommand(new InvoiceRefSequence()));
        $tester->execute(['order' => $order->getRef()]);
        $tester->execute(['order' => (string) $order->getId()]);

        self::assertSame(['1000', '1001'], [$this->invoiceRefOf($order), $this->counter()]);
    }

    public function testTheConfigurationFormOffersTheStatusesAndRefusesACounterThatCannotBeIncremented(): void
    {
        $form = $this->getService(TheliaFormFactory::class)->createForm(ConfigurationForm::getName())->getForm();

        $form->submit(['invoice' => 'FA-', 'statuses' => ['paid', 'refunded']]);

        self::assertSame(
            [['paid', 'refunded'], false, true],
            [$form->get('statuses')->getData(), $form->get('invoice')->isValid(), $form->get('statuses')->isValid()],
        );
    }

    private function changeStatus(Order $order, string $statusCode): void
    {
        $status = OrderStatusQuery::create()->findOneByCode($statusCode)
            ?? throw new \RuntimeException("Order status '$statusCode' is missing.");

        $event = new OrderEvent($order);
        $event->setStatus($status->getId());
        $event->forceStatusTransition();

        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
    }

    private function ensureStatus(string $code): void
    {
        if (null === OrderStatusQuery::create()->findOneByCode($code)) {
            $this->fixtures->orderStatus(['code' => $code]);
        }
    }

    private function orderWithInvoiceRef(string $invoiceRef): Order
    {
        $order = $this->fixtures->order();
        $this->execute(\sprintf("UPDATE `order` SET `invoice_ref` = '%s' WHERE `id` = %d", $invoiceRef, $order->getId()));

        return $order;
    }

    private function invoiceRefOf(Order $order): ?string
    {
        $statement = $this->getPropelConnection()->prepare('SELECT `invoice_ref` FROM `order` WHERE `id` = :id');
        $statement->bindValue(':id', $order->getId(), \PDO::PARAM_INT);
        $statement->execute();

        $invoiceRef = $statement->fetchColumn();

        return \is_string($invoiceRef) ? $invoiceRef : null;
    }

    private function counter(): ?string
    {
        $statement = $this->getPropelConnection()->prepare('SELECT `value` FROM `config` WHERE `name` = :name');
        $statement->bindValue(':name', InvoiceRefSequence::CONFIG_NAME, \PDO::PARAM_STR);
        $statement->execute();

        $value = $statement->fetchColumn();

        return \is_string($value) ? $value : null;
    }

    private function execute(string $sql): void
    {
        $this->getPropelConnection()->exec($sql);
    }
}
