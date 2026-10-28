<?php

declare(strict_types=1);

use App\Environment;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mssql\Connection;
use Yiisoft\Db\Mssql\Driver;
use Yiisoft\Db\Mssql\Dsn;

return [
    ConnectionInterface::class => [
        'class' => Connection::class,
        '__construct()' => [
            'driver' => new Driver(
                new Dsn(
                    host: Environment::dbHost(),
                    databaseName: Environment::isTest() ? Environment::dbTestName() : Environment::dbName(),
                    port: (string) Environment::dbPort(),
                    options: ['TrustServerCertificate' => Environment::dbTrustServerCertificate() ? 'true' : 'false'],
                ),
                Environment::isTest() ? Environment::dbTestUser() : Environment::dbUser(),
                Environment::isTest() ? Environment::dbTestPassword() : Environment::dbPassword(),
            ),
        ],
    ],
];
