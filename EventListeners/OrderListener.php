<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace InvoiceRef\EventListeners;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Lock\Factory;
use Symfony\Component\Lock\Store\FlockStore;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderQuery;

/**
 * Class OrderListener
 * @package InvoiceRef\EventListeners
 * @author manuel raynaud <mraynaud@openstudio.fr>
 */
class OrderListener implements EventSubscriberInterface
{
    /**
     * @param OrderEvent $event
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function implementInvoice(OrderEvent $event)
    {
        $order = $event->getOrder();

        if ($order->isPaid() && null === $order->getInvoiceRef()) {
            // Protect genration against concurrent executions
            $flockFactory = new Factory(new FlockStore());

            $lock = $flockFactory->createLock('invoice-ref-generation');

            // Acquire a blocking lock
            $lock->acquire(true);

            try {
                $invoiceRef = ConfigQuery::create()
                    ->findOneByName('invoiceRef');

                if (null === $invoiceRef) {
                    throw new \RuntimeException("you must set an invoice ref in your admin panel");
                }

                $value = $invoiceRef->getValue();
                if (null !== OrderQuery::create()->filterByInvoiceRef($value)->findOne()){
                    $value++;
                }

                $order->setInvoiceRef($value)
                    ->save();

                $invoiceRef
                    ->setValue(++$value)
                    ->save();
            } finally {
                // Always release lock !
                $lock->release();
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
            TheliaEvents::ORDER_UPDATE_STATUS => ['implementInvoice', 100]
        ];
    }
}
