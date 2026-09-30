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

namespace InvoiceRef\Command;

use InvoiceRef\Service\InvoiceRefSequence;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Model\OrderQuery;

/**
 * Gives an order the invoice number its numbering failed to give it (the failure is logged with the order id), once
 * the cause is fixed. An order that already has a number keeps it.
 */
#[AsCommand(name: 'invoiceref:assign', description: 'Give an order without invoice number the next number of the series')]
final class AssignInvoiceRefCommand extends Command
{
    public function __construct(
        private readonly InvoiceRefSequence $sequence,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('order', InputArgument::REQUIRED, 'Order id or reference');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = (string) $input->getArgument('order');

        $order = ctype_digit($identifier)
            ? OrderQuery::create()->findPk((int) $identifier)
            : OrderQuery::create()->findOneByRef($identifier);

        if (null === $order) {
            $output->writeln(\sprintf('<error>No order "%s".</error>', $identifier));

            return self::FAILURE;
        }

        if (!$this->sequence->assignTo($order)) {
            $output->writeln(\sprintf('Order %s already has an invoice number.', $order->getRef()));

            return self::SUCCESS;
        }

        $output->writeln(\sprintf('Order %s: invoice number %s.', $order->getRef(), $order->getInvoiceRef()));

        return self::SUCCESS;
    }
}
