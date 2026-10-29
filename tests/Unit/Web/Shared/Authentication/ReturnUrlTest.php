<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Authentication;

use App\Web\Shared\Authentication\ReturnUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Session\SessionInterface;

use function PHPUnit\Framework\assertSame;

final class ReturnUrlTest extends TestCase
{
    public function testPullWithoutRememberedPageReturnsHome(): void
    {
        assertSame('/', $this->returnUrl()->pull());
    }

    public function testRememberedPageIsReturnedOnce(): void
    {
        $returnUrl = $this->returnUrl();
        $returnUrl->remember('/sites?page=2');

        assertSame('/sites?page=2', $returnUrl->pull());
        assertSame('/', $returnUrl->pull());
    }

    #[DataProvider('pathsLeavingTheSite')]
    public function testPathCanNeverPointToAnotherHost(string $path, string $expected): void
    {
        $returnUrl = $this->returnUrl();
        $returnUrl->remember($path);

        assertSame($expected, $returnUrl->pull());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathsLeavingTheSite(): iterable
    {
        yield 'protocol-relative' => ['//evil.com/x', '/evil.com/x'];
        yield 'backslash' => ['/\\evil.com', '/evil.com'];
        yield 'several slashes' => ['///evil.com', '/evil.com'];
        yield 'no leading slash' => ['sites', '/sites'];
    }

    private function returnUrl(): ReturnUrl
    {
        return new ReturnUrl(new class implements SessionInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get(string $key, $default = null)
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, $value): void
            {
                $this->data[$key] = $value;
            }

            public function close(): void {}

            public function open(): void {}

            public function isActive(): bool
            {
                return true;
            }

            public function getId(): string
            {
                return 'test';
            }

            public function setId(string $sessionId): void {}

            public function regenerateId(): void {}

            public function discard(): void {}

            public function getName(): string
            {
                return 'test';
            }

            /**
             * @return array<string, mixed>
             */
            public function all(): array
            {
                return $this->data;
            }

            public function remove(string $key): void
            {
                unset($this->data[$key]);
            }

            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }

            public function pull(string $key, $default = null)
            {
                $value = $this->get($key, $default);
                $this->remove($key);
                return $value;
            }

            public function clear(): void
            {
                $this->data = [];
            }

            public function destroy(): void
            {
                $this->data = [];
            }

            public function getCookieParameters(): array
            {
                return session_get_cookie_params();
            }
        });
    }
}
