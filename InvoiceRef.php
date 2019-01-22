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

namespace InvoiceRef;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderQuery;
use Thelia\Module\BaseModule;

class InvoiceRef extends BaseModule
{
    const DOMAIN_NAME = "invoiceref";

    public function postActivation(ConnectionInterface $con = null)
    {
        if (null === ConfigQuery::read('invoiceRef', null)) {
            if (null !== $lastOderPaid = OrderQuery::create()
                ->filterByInvoiceRef(null, Criteria::NOT_EQUAL)
                ->orderByInvoiceRef(Criteria::DESC)
                ->findOne()) {
                $nextRef = (int) $lastOderPaid->getInvoiceRef();
                $nextRef++;

                ConfigQuery::write('invoiceRef', $nextRef, true, true);
            } else {
                ConfigQuery::write('invoiceRef', 1, true, true);
            }
        }
    }
}
