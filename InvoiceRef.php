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

namespace InvoiceRef;

use InvoiceRef\Service\InvoiceRefSequence;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Module\BaseModule;

class InvoiceRef extends BaseModule
{
    public const DOMAIN_NAME = 'invoiceref';

    /**
     * The core numbering of Thelia 3 is on in a fresh install: two numberings on the same orders would run two
     * series, so it is turned off when this module takes over.
     */
    public const CORE_NUMBERING_CONFIG_NAME = 'invoice_ref_auto';

    public function postActivation(?ConnectionInterface $con = null): void
    {
        ConfigQuery::write(self::CORE_NUMBERING_CONFIG_NAME, '0');

        if (null !== ConfigQuery::read(InvoiceRefSequence::CONFIG_NAME)) {
            return;
        }

        ConfigQuery::write(InvoiceRefSequence::CONFIG_NAME, (string) ($this->highestNumericInvoiceRef($con) + 1), true, true);
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Tests/*',
            ])
            ->autowire()
            ->autoconfigure();
    }

    /**
     * invoice_ref is a VARCHAR column: sorting it would put "999" after "1000", so the highest number is computed
     * on its numeric value.
     */
    private function highestNumericInvoiceRef(?ConnectionInterface $connection): int
    {
        $connection ??= Propel::getConnection(OrderTableMap::DATABASE_NAME);

        $statement = $connection->prepare(
            "SELECT MAX(CAST(`invoice_ref` AS UNSIGNED)) FROM `order` WHERE `invoice_ref` REGEXP '^[0-9]+$'"
        );
        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}
