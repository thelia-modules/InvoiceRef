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

use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderStatus;

/**
 * The order statuses that give an order its invoice number, stored as a comma-separated list of status codes in
 * the `invoiceRefStatuses` configuration. Without it, only the paid status numbers, as before the setting existed.
 */
final readonly class NumberedStatuses
{
    public const CONFIG_NAME = 'invoiceRefStatuses';

    public const DEFAULT_CODES = [OrderStatus::CODE_PAID];

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        $stored = ConfigQuery::read(self::CONFIG_NAME);

        if (!\is_string($stored)) {
            return self::DEFAULT_CODES;
        }

        $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', $stored)), static fn (string $code): bool => '' !== $code)));

        return [] === $codes ? self::DEFAULT_CODES : $codes;
    }

    /**
     * A status matches by its own code or by the native code it stands for: a custom status declared as equivalent
     * to "paid" numbers like "paid", as {@see OrderStatus::isPaid()} always did.
     */
    public function includes(OrderStatus $status): bool
    {
        $codes = $this->codes();

        return \in_array($status->getCode(), $codes, true) || \in_array($status->getEffectiveCode(), $codes, true);
    }

    /**
     * @param list<string> $codes
     */
    public function save(array $codes): void
    {
        ConfigQuery::write(self::CONFIG_NAME, implode(',', $codes), true, true);
    }
}
