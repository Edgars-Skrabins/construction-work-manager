<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertSame;

final class SessionCookieTest extends TestCase
{
    #[DataProvider('environments')]
    public function testSessionCookieIsSecureOnlyInProduction(string $environment, bool $secure): void
    {
        $session = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 3), environment: $environment))
            ->getContainer()
            ->get(SessionInterface::class);
        assertInstanceOf(SessionInterface::class, $session);

        $session->open();
        $cookie = $session->getCookieParameters();
        $session->destroy();

        assertSame($secure, $cookie['secure']);
        assertSame(true, $cookie['httponly']);
        assertSame('Lax', $cookie['samesite']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function environments(): iterable
    {
        yield 'production' => [Environment::PROD, true];
        yield 'development' => [Environment::DEV, false];
        yield 'test' => [Environment::TEST, false];
    }
}
