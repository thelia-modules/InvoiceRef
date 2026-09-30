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
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Test\IntegrationTestCase;

/**
 * Two processes numbering at the same instant. The data must be committed for the child processes to see it: this
 * suite runs outside a transaction, on a test database of its own (name ending with `_test`).
 */
final class ConcurrentNumberingTest extends IntegrationTestCase
{
    private const DRAWS_PER_PROCESS = 150;

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
    }

    protected function tearDown(): void
    {
        // Committed: removed by hand, the fixture factory would otherwise meet its own unique values on the next run.
        if (null !== $this->order) {
            $connection = $this->getPropelConnection();
            $connection->exec('DELETE FROM `order` WHERE `id` = '.$this->order->getId());
            $connection->exec('DELETE FROM `cart` WHERE `id` = '.$this->order->getCartId());
            $connection->exec('DELETE FROM `customer` WHERE `id` = '.$this->order->getCustomerId());
            $this->order = null;
        }

        parent::tearDown();
    }

    public function testTwoProcessesNeverDrawTheSameNumber(): void
    {
        $start = random_int(100000000, 900000000);
        ConfigQuery::write(InvoiceRefSequence::CONFIG_NAME, (string) $start, true, true);

        [$first, $second] = $this->runConcurrently(
            ['draw', (string) self::DRAWS_PER_PROCESS],
            ['draw', (string) self::DRAWS_PER_PROCESS],
        );

        $drawn = array_merge($first, $second);
        sort($drawn, \SORT_NUMERIC);
        $expected = array_map('strval', range($start, $start + 2 * self::DRAWS_PER_PROCESS - 1));

        self::assertSame(
            [$expected, (string) ($start + 2 * self::DRAWS_PER_PROCESS)],
            [$drawn, $this->counter()],
        );
    }

    public function testTwoProcessesNumberingTheSameOrderConsumeOneNumber(): void
    {
        $start = random_int(100000000, 900000000);
        ConfigQuery::write(InvoiceRefSequence::CONFIG_NAME, (string) $start, true, true);
        $order = $this->order = $this->createFixtureFactory()->order();

        $assigned = $this->runConcurrently(['assign', (string) $order->getId()], ['assign', (string) $order->getId()]);
        sort($assigned);

        $statement = $this->getPropelConnection()->prepare('SELECT `invoice_ref` FROM `order` WHERE `id` = :id');
        $statement->bindValue(':id', $order->getId(), \PDO::PARAM_INT);
        $statement->execute();

        self::assertSame(
            [[false, true], (string) $start, (string) ($start + 1)],
            [$assigned, $statement->fetchColumn(), $this->counter()],
        );
    }

    /**
     * Starts one worker per argument list, waits until every one has booted, releases them together and returns
     * their decoded results in the same order.
     *
     * @param list<string> ...$workerArguments
     *
     * @return list<mixed>
     */
    private function runConcurrently(array ...$workerArguments): array
    {
        $workers = [];

        foreach ($workerArguments as $arguments) {
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/Concurrency/worker.php', ...$arguments],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                getcwd() ?: null,
            );
            self::assertIsResource($process);
            $workers[] = [$process, $pipes];
        }

        foreach ($workers as [$process, $pipes]) {
            $ready = fgets($pipes[1]);
            if ("ready\n" !== $ready) {
                self::fail('A worker did not start: '.$ready.stream_get_contents($pipes[2]));
            }
        }

        foreach ($workers as [$process, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }

        $results = [];

        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            if (0 !== proc_close($process)) {
                self::fail('A worker failed: '.$output.$errors);
            }

            $results[] = json_decode(trim((string) $output), true, 512, \JSON_THROW_ON_ERROR);
        }

        return $results;
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
