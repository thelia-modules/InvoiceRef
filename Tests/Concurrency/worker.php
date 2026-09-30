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

/*
 * Child process of ConcurrentNumberingTest, started from the root of the project:
 *   php worker.php draw <count>     draws <count> numbers, one transaction each
 *   php worker.php assign <orderId> gives the order an invoice number
 * Boots the kernel, prints "ready", waits for a line on its standard input, then prints its result as JSON.
 */

use InvoiceRef\Service\InvoiceRefSequence;
use Propel\Runtime\Propel;
use Thelia\Model\OrderQuery;

require dirname(__DIR__).'/bootstrap.php';

[, $mode, $argument] = $argv;

$kernelClass = $_SERVER['KERNEL_CLASS'] ?? 'App\Kernel';
$kernel = new $kernelClass('test', false);
$kernel->boot();

$connection = Propel::getConnection('TheliaMain');
$sequence = new InvoiceRefSequence();
// Loaded before the start signal: both processes hold the order as it stood, without an invoice number.
$order = 'assign' === $mode ? OrderQuery::create()->findPk((int) $argument) : null;

echo "ready\n";
fgets(\STDIN);

$result = match ($mode) {
    'draw' => (static function () use ($connection, $sequence, $argument): array {
        $numbers = [];

        for ($draw = 0; $draw < (int) $argument; ++$draw) {
            $connection->beginTransaction();
            $numbers[] = $sequence->next($connection);
            $connection->commit();
        }

        return $numbers;
    })(),
    'assign' => $sequence->assignTo($order ?? throw new RuntimeException('Unknown order.'), $connection),
    default => throw new InvalidArgumentException('Unknown mode.'),
};

echo json_encode($result, \JSON_THROW_ON_ERROR)."\n";
