<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Environment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class EnvironmentTest extends TestCase
{
    private const KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_HOST_PATH',
        'DB_HOST',
        'DB_PORT',
        'DB_TRUST_SERVER_CERTIFICATE',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_TEST_NAME',
        'DB_TEST_USER',
        'DB_TEST_PASSWORD',
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->originalEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
        Environment::prepare();
    }

    public function testAppEnvIsReadFromEnvironment(): void
    {
        $this->setEnv('APP_ENV', 'test');

        assertSame('test', Environment::appEnv());
    }

    public function testAppEnvDefaultsToProdWhenNotSet(): void
    {
        $this->unsetEnv('APP_ENV');

        assertSame('prod', Environment::appEnv());
    }

    public function testAppEnvDefaultsToProdWhenEmpty(): void
    {
        $this->setEnv('APP_ENV', '');

        assertSame('prod', Environment::appEnv());
    }

    public function testInvalidAppEnvThrows(): void
    {
        $this->unsetEnv('APP_ENV');
        putenv('APP_ENV=staging');

        try {
            Environment::prepare();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            assertSame('APP_ENV="staging" is invalid. Valid values are "dev", "test", "prod".', $e->getMessage());
        } finally {
            putenv('APP_ENV');
        }
    }

    public function testIsDev(): void
    {
        $this->setEnv('APP_ENV', 'dev');

        assertTrue(Environment::isDev());
        assertFalse(Environment::isTest());
        assertFalse(Environment::isProd());
    }

    public function testIsTest(): void
    {
        $this->setEnv('APP_ENV', 'test');

        assertTrue(Environment::isTest());
        assertFalse(Environment::isDev());
        assertFalse(Environment::isProd());
    }

    public function testIsProd(): void
    {
        $this->setEnv('APP_ENV', 'prod');

        assertTrue(Environment::isProd());
        assertFalse(Environment::isDev());
        assertFalse(Environment::isTest());
    }

    public function testAppDebugDefaultsToFalse(): void
    {
        $this->unsetEnv('APP_DEBUG');

        assertFalse(Environment::appDebug());
    }

    public function testAppDebugTrue(): void
    {
        $this->setEnv('APP_DEBUG', 'true');

        assertTrue(Environment::appDebug());
    }

    public function testAppDebugFalse(): void
    {
        $this->setEnv('APP_DEBUG', 'false');

        assertFalse(Environment::appDebug());
    }

    public function testAppHostPathDefaultsToNull(): void
    {
        $this->unsetEnv('APP_HOST_PATH');

        assertNull(Environment::appHostPath());
    }

    public function testAppHostPathIsRead(): void
    {
        $this->setEnv('APP_HOST_PATH', '/projects/myapp');

        assertSame('/projects/myapp', Environment::appHostPath());
    }

    public function testAppHostPathEmptyStringTreatedAsNull(): void
    {
        $this->setEnv('APP_HOST_PATH', '');

        assertNull(Environment::appHostPath());
    }

    public function testDbSettingsAreRead(): void
    {
        $this->setEnv('DB_HOST', 'db.example');
        $this->setEnv('DB_PORT', '14330');
        $this->setEnv('DB_TRUST_SERVER_CERTIFICATE', 'true');
        $this->setEnv('DB_NAME', 'app_db');
        $this->setEnv('DB_USER', 'app_user');
        $this->setEnv('DB_PASSWORD', 'app_secret');
        $this->setEnv('DB_TEST_NAME', 'app_db_test');
        $this->setEnv('DB_TEST_USER', 'test_user');
        $this->setEnv('DB_TEST_PASSWORD', 'test_secret');

        assertSame('db.example', Environment::dbHost());
        assertSame(14330, Environment::dbPort());
        assertTrue(Environment::dbTrustServerCertificate());
        assertSame('app_db', Environment::dbName());
        assertSame('app_user', Environment::dbUser());
        assertSame('app_secret', Environment::dbPassword());
        assertSame('app_db_test', Environment::dbTestName());
        assertSame('test_user', Environment::dbTestUser());
        assertSame('test_secret', Environment::dbTestPassword());
    }

    public function testDbSettingsDefaults(): void
    {
        foreach (self::KEYS as $key) {
            if (str_starts_with($key, 'DB_')) {
                $this->unsetEnv($key);
            }
        }

        assertSame('localhost', Environment::dbHost());
        assertSame(1433, Environment::dbPort());
        assertFalse(Environment::dbTrustServerCertificate());
        assertSame('', Environment::dbName());
        assertSame('', Environment::dbUser());
        assertSame('', Environment::dbPassword());
        assertSame('', Environment::dbTestName());
        assertSame('', Environment::dbTestUser());
        assertSame('', Environment::dbTestPassword());
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        Environment::prepare();
    }

    private function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key]);
        Environment::prepare();
    }
}
