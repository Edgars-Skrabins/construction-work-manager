<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertSame;

final class NoStoreTest extends IntegrationTestCase
{
    use WebClient;

    #[DataProvider('loggedInPages')]
    public function testLoggedInPagesAreNeverStored(string $email, string $path): void
    {
        $this->logIn($email, '123123123');

        $response = $this->request('GET', $path);

        assertSame(200, $response->getStatusCode());
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function loggedInPages(): iterable
    {
        yield 'admin employee list' => ['admin@mail.test', '/employees'];
        yield 'manager assignment list' => ['manager@mail.test', '/assignments'];
        yield 'employee my assignments' => ['employee@mail.test', '/my-assignments'];
    }

    #[DataProvider('publicPages')]
    public function testPublicPagesAreNeverStored(string $path): void
    {
        $response = $this->request('GET', $path);

        assertSame(200, $response->getStatusCode());
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPages(): iterable
    {
        yield 'login' => ['/login'];
        yield 'set password' => ['/set-password'];
    }

    public function testGuestRedirectToLoginIsNeverStored(): void
    {
        $response = $this->request('GET', '/employees');

        assertSame(302, $response->getStatusCode());
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }
}
