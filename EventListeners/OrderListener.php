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

namespace InvoiceRef\EventListeners;

use InvoiceRef\Service\InvoiceRefSequence;
use InvoiceRef\Service\NumberedStatuses;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * Gives an order its invoice number when it reaches one of the numbered statuses ({@see NumberedStatuses}), once.
 *
 * Listens after `Thelia\Action\Order::updateStatus()` (priority 128), which has saved the new status. That save is
 * committed only if no caller wrapped the event in a transaction of its own (the order API processor does): the
 * numbering then runs inside that transaction and must never make it fail ({@see InvoiceRefSequence::assignTo()}).
 */
final readonly class OrderListener implements EventSubscriberInterface
{
    public function __construct(
        private NumberedStatuses $numberedStatuses,
        private InvoiceRefSequence $sequence,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['implementInvoice', 100],
        ];
    }

    public function implementInvoice(OrderEvent $event): void
    {
        $order = $event->getOrder();

        if (null !== $order->getInvoiceRef() && '' !== $order->getInvoiceRef()) {
            return;
        }

        if (!$this->numberedStatuses->includes($order->getOrderStatus())) {
            return;
        }

        try {
            $this->sequence->assignTo($order);
        } catch (\Throwable $exception) {
            // Rethrowing would abort the listeners still due to run (coupon consumption, confirmation e-mails), fail
            // the payment module's callback, and cancel the status change when the caller wraps it in a transaction.
            // The order keeps no invoice number: `invoiceref:assign` gives it one once the cause is fixed.
            $this->logger->error('InvoiceRef: failed to give order #{orderId} an invoice number: {message}', [
                'orderId' => $order->getId(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
