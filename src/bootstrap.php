<?php

declare(strict_types=1);

use App\Environment;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (empty($_ENV['APP_ENV']) && class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

Environment::prepare();
