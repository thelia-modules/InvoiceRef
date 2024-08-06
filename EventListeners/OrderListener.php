<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*      Copyright (c) OpenStudio */
/*      email : dev@thelia.net */
/*      web : http://www.thelia.net */

/*      For the full copyright and license information, please view the LICENSE.txt */
/*      file that was distributed with this source code. */

namespace InvoiceRef\EventListeners;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;

/**
 * Class OrderListener.
 *
 * @author manuel raynaud <mraynaud@openstudio.fr>
 */
class OrderListener implements EventSubscriberInterface
{
    /**
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function implementInvoice(OrderEvent $event): void
    {
        $order = $event->getOrder();

        if ($order->isPaid() && null === $order->getInvoiceRef()) {
            $lock = null;

            // Try to acquire lock, being fault-tolerant if it can't be acquired
            // for whatever reason.
            try {
                $flockFactory = new LockFactory(new FlockStore());

                $lock = $flockFactory->createLock('invoice-ref-generation');

                // Acquire a blocking lock
                $lock->acquire(true);
            } catch (\Exception $ex) {
                Tlog::getInstance()->error('Failed to acquire lock : '.$ex->getMessage());
            }

            try {
                $invoiceRef = ConfigQuery::create()
                    ->findOneByName('invoiceRef');

                if (null === $invoiceRef) {
                    throw new \RuntimeException('you must set an invoice ref in your admin panel');
                }

                $value = $invoiceRef->getValue();
                $order->setInvoiceRef($value)
                    ->save();

                $invoiceRef
                    ->setValue(++$value)
                    ->save();
            } finally {
                // Always release lock !
                $lock?->release();
            }
        }
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *  * The method name to call (priority defaults to 0)
     *  * An array composed of the method name to call and the priority
     *  * An array of arrays composed of the method names to call and respective
     *    priorities, or 0 if unset
     *
     * For instance:
     *
     *  * array('eventName' => 'methodName')
     *  * array('eventName' => array('methodName', $priority))
     *  * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array The event names to listen to
     *
     * @api
     */
    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['implementInvoice', 100],
        ];
    }
}
