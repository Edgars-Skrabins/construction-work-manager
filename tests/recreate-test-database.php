<?php

declare(strict_types=1);

use App\Environment;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$name = Environment::dbTestName();

if (preg_match('/^[A-Za-z0-9_]+_test$/', $name) !== 1) {
    fwrite(STDERR, sprintf('DB_TEST_NAME="%s" is invalid. It must match /^[A-Za-z0-9_]+_test$/.' . PHP_EOL, $name));
    exit(1);
}

$pdo = new PDO(
    sprintf(
        'sqlsrv:Server=%s,%d;Database=master;TrustServerCertificate=%s',
        Environment::dbHost(),
        Environment::dbPort(),
        Environment::dbTrustServerCertificate() ? 'true' : 'false',
    ),
    Environment::dbTestUser(),
    Environment::dbTestPassword(),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$pdo->exec(sprintf(
    "IF DB_ID(N'%1\$s') IS NOT NULL BEGIN ALTER DATABASE [%1\$s] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [%1\$s]; END",
    $name,
));
$pdo->exec(sprintf('CREATE DATABASE [%s]', $name));

fwrite(STDOUT, sprintf('Recreated test database %s.' . PHP_EOL, $name));
