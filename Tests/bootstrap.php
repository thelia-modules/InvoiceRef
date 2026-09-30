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
 * Run from the root of the Thelia project the module is installed in, against its own test database
 * (see the Readme):
 *   DATABASE_NAME=invoiceref_test php bin/test-prepare
 *   DATABASE_NAME=invoiceref_test vendor/bin/phpunit --bootstrap vendor/thelia/modules/InvoiceRef/Tests/bootstrap.php vendor/thelia/modules/InvoiceRef/Tests
 */

use Symfony\Component\Dotenv\Dotenv;

$projectRoot = getcwd();

// The compiled container holds the services of the modules active in the database it was built against and is
// reused as is outside debug mode: this suite keeps its own, apart from the one of the project's test database.
if (!defined('THELIA_CACHE_DIR')) {
    define('THELIA_CACHE_DIR', $projectRoot.'/var/cache/invoiceref-tests/');
}

require $projectRoot.'/bootstrap.php';
$loader = require $projectRoot.'/vendor/autoload.php';
// The tests start child processes before the kernel registers the namespaces of the modules.
$loader->addPsr4('InvoiceRef\\', dirname(__DIR__).'/');

(new Dotenv())->bootEnv($projectRoot.'/.env');
