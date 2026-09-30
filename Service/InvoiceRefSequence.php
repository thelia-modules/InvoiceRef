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

namespace InvoiceRef\Service;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Core\Cache\ConfigCacheService;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;

/**
 * The invoice number series held by the `invoiceRef` configuration: the value stored there is the next number to
 * hand out.
 *
 * Every number is drawn under an exclusive lock on that configuration row, taken in the database: two processes,
 * on the same server or not, never read the same value. A module sharing the series (credit notes numbered like
 * invoices, for instance) draws its numbers through {@see next()} to be serialized with the orders.
 *
 * The writes are plain SQL statements, never a Propel save: a failed save rolls back a nested Propel transaction,
 * which leaves the transaction of the caller uncommittable.
 */
final readonly class InvoiceRefSequence
{
    public const CONFIG_NAME = 'invoiceRef';

    private const SAVEPOINT = 'invoice_ref_assignment';

    public function __construct(
        private ?ConfigCacheService $configCache = null,
    ) {
    }

    /**
     * Gives the order the next invoice number, unless it already has one. Returns whether a number was assigned.
     *
     * Payment gateways retry their notifications, sometimes in parallel: the order row is locked and its invoice
     * number read again under the lock, so a retry never consumes a second number for the same order.
     *
     * The status change often runs inside a transaction of its caller (the order API processor, a payment module).
     * A failure here must not cancel it: what can predictably fail (no counter, a counter that cannot be incremented)
     * is checked before anything is written, and the work runs under a savepoint when a transaction is already open,
     * so that a failure only undoes the numbering.
     */
    public function assignTo(Order $order, ?ConnectionInterface $connection = null): bool
    {
        $connection ??= Propel::getConnection(OrderTableMap::DATABASE_NAME);

        $this->assertCounterCanBeDrawn($connection);

        $insideTransaction = $connection->inTransaction();
        $insideTransaction ? $connection->exec('SAVEPOINT '.self::SAVEPOINT) : $connection->beginTransaction();

        try {
            if (null !== $this->readInvoiceRefLocked($order->getId(), $connection)) {
                $this->end($connection, $insideTransaction);

                return false;
            }

            $invoiceRef = $this->next($connection);

            // A status other than paid (refunded, for instance) has not dated the invoice: the order model only does it
            // on paid. No order version either, as with the core numbering: numbering the invoice changes nothing else.
            $statement = $connection->prepare(
                'UPDATE `order` SET `invoice_ref` = :invoice_ref, `invoice_date` = COALESCE(`invoice_date`, NOW()), `updated_at` = NOW() WHERE `id` = :id'
            );
            $statement->bindValue(':invoice_ref', $invoiceRef, \PDO::PARAM_STR);
            $statement->bindValue(':id', $order->getId(), \PDO::PARAM_INT);
            $statement->execute();

            $this->end($connection, $insideTransaction);
        } catch (\Throwable $throwable) {
            $this->abort($connection, $insideTransaction);

            throw $throwable;
        }

        $this->refreshConfigurationCache();
        $this->reflectOnModel($order, $invoiceRef);

        return true;
    }

    /**
     * Draws the next number of the series and moves the counter past it.
     *
     * Must run inside a transaction: the configuration row stays locked until that transaction ends, so the caller
     * saves what carries the number before anyone else can draw. A number already carried by an order (a counter
     * set back by hand, a series imported with duplicates) is skipped.
     */
    public function next(ConnectionInterface $connection): string
    {
        if (!$connection->inTransaction()) {
            throw new \LogicException('An invoice number must be drawn inside a transaction, the counter lock lasts until it ends.');
        }

        [$configId, $value] = $this->readCounterLocked($connection);

        while ($this->isUsedByAnOrder($value, $connection)) {
            $value = $this->increment($value);
        }

        $statement = $connection->prepare('UPDATE `config` SET `value` = :value, `updated_at` = NOW() WHERE `id` = :id');
        $statement->bindValue(':value', $this->increment($value), \PDO::PARAM_STR);
        $statement->bindValue(':id', $configId, \PDO::PARAM_INT);
        $statement->execute();

        return $value;
    }

    /**
     * Whether the series can go on from this value: digits, any reference ending with digits, or letters and digits
     * only.
     */
    public static function canIncrement(string $value): bool
    {
        $value = trim($value);

        return '' !== $value && (1 === preg_match('/\d$/', $value) || ctype_alnum($value));
    }

    /**
     * Read without a lock, before any write: the same checks, repeated under the lock, then fail only if the counter
     * is changed in between.
     */
    private function assertCounterCanBeDrawn(ConnectionInterface $connection): void
    {
        $statement = $connection->prepare('SELECT `value` FROM `config` WHERE `name` = :name');
        $statement->bindValue(':name', self::CONFIG_NAME, \PDO::PARAM_STR);
        $statement->execute();

        $this->assertIncrementable($statement->fetchColumn());
    }

    private function assertIncrementable(mixed $value): void
    {
        if (!\is_string($value) || '' === trim($value)) {
            throw new \RuntimeException('You must set an invoice ref in your admin panel.');
        }

        if (!self::canIncrement($value)) {
            throw new \RuntimeException(\sprintf('The invoice ref "%s" cannot be incremented: end it with a number.', $value));
        }
    }

    /**
     * @return array{int, string}
     */
    private function readCounterLocked(ConnectionInterface $connection): array
    {
        $statement = $connection->prepare('SELECT `id`, `value` FROM `config` WHERE `name` = :name FOR UPDATE');
        $statement->bindValue(':name', self::CONFIG_NAME, \PDO::PARAM_STR);
        $statement->execute();

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            throw new \RuntimeException('You must set an invoice ref in your admin panel.');
        }

        $this->assertIncrementable($row['value']);

        return [(int) $row['id'], trim((string) $row['value'])];
    }

    private function readInvoiceRefLocked(int $orderId, ConnectionInterface $connection): ?string
    {
        $statement = $connection->prepare('SELECT `invoice_ref` FROM `order` WHERE `id` = :id FOR UPDATE');
        $statement->bindValue(':id', $orderId, \PDO::PARAM_INT);
        $statement->execute();

        $invoiceRef = $statement->fetchColumn();

        return \is_string($invoiceRef) && '' !== $invoiceRef ? $invoiceRef : null;
    }

    private function isUsedByAnOrder(string $invoiceRef, ConnectionInterface $connection): bool
    {
        $statement = $connection->prepare('SELECT COUNT(*) FROM `order` WHERE `invoice_ref` = :invoice_ref');
        $statement->bindValue(':invoice_ref', $invoiceRef, \PDO::PARAM_STR);
        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * A numeric reference is incremented as a number, as the module always did ("0099" becomes "100"). A reference
     * ending with digits keeps its prefix and its width ("FA-0099" becomes "FA-0100"); any other alphanumeric
     * reference follows the alphanumeric increment ("AZ" becomes "BA").
     */
    private function increment(string $value): string
    {
        if (ctype_digit($value)) {
            return (string) ((int) $value + 1);
        }

        if (1 === preg_match('/^(.*\D)(\d+)$/', $value, $matches)) {
            return $matches[1].str_pad((string) ((int) $matches[2] + 1), \strlen($matches[2]), '0', \STR_PAD_LEFT);
        }

        if (ctype_alnum($value)) {
            return str_increment($value);
        }

        throw new \RuntimeException(\sprintf('The invoice ref "%s" cannot be incremented: end it with a number.', $value));
    }

    private function end(ConnectionInterface $connection, bool $insideTransaction): void
    {
        $insideTransaction ? $connection->exec('RELEASE SAVEPOINT '.self::SAVEPOINT) : $connection->commit();
    }

    private function abort(ConnectionInterface $connection, bool $insideTransaction): void
    {
        if (!$insideTransaction) {
            $connection->rollBack();

            return;
        }

        try {
            $connection->exec('ROLLBACK TO SAVEPOINT '.self::SAVEPOINT);
        } catch (\Throwable) {
            // A deadlock has already rolled the whole transaction back and dropped the savepoint: the commit of the
            // caller reports it.
        }
    }

    /**
     * The counter was written in SQL: the configuration cache of the shop, which the back office reads, is reloaded
     * the way a configuration save does it.
     */
    private function refreshConfigurationCache(): void
    {
        $this->configCache?->initCacheConfigs(true);
    }

    /**
     * The event carries this model to the listeners still due to run (invoice e-mail, PDF): it gets the values just
     * written, without being marked as modified.
     */
    private function reflectOnModel(Order $order, string $invoiceRef): void
    {
        $order->setInvoiceRef($invoiceRef);
        $order->resetModified(OrderTableMap::COL_INVOICE_REF);

        if (null === $order->getInvoiceDate()) {
            $order->setInvoiceDate(new \DateTime());
            $order->resetModified(OrderTableMap::COL_INVOICE_DATE);
        }
    }
}
