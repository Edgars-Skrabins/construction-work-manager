<?php

declare(strict_types=1);

use App\Environment;
use Psr\Log\LogLevel;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\HtmlRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$root = dirname(__DIR__);

require_once $root . '/src/bootstrap.php';

if (PHP_SAPI === 'cli-server') {
    /** @var string $path */
    $path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    if (is_file(__DIR__ . $path)) {
        return false;
    }

    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

$runner = new HttpApplicationRunner(
    rootPath: $root,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
    temporaryErrorHandler: new ErrorHandler(
        new Logger(
            [
                (new StreamTarget())->setLevels([
                    LogLevel::EMERGENCY,
                    LogLevel::ERROR,
                    LogLevel::WARNING,
                ]),
            ],
        ),
        new HtmlRenderer(),
    ),
);
$runner->run();
