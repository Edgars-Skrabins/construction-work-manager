<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class AdminPagesTest extends IntegrationTestCase
{
    use WebClient;

    protected function setUp(): void
    {
        $this->logIn('admin@mail.test', '123123123');
    }

    public function testNewSetupCodeForALoginWithAPasswordShowsTheReason(): void
    {
        $response = $this->submit('/employees/4/setup-code', []);
        $body = (string) $response->getBody();

        assertSame(409, $response->getStatusCode());
        assertStringContainsString('This employee has no pending login.', $body);
        assertStringNotContainsString('font-monospace', $body);
    }

    public function testSetupCodePageIsNeverCached(): void
    {
        $response = $this->request('GET', '/employees/4/setup-code');

        assertSame(200, $response->getStatusCode());
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testOwnRowHasNoDeleteButton(): void
    {
        $body = (string) $this->request('GET', '/employees')->getBody();

        assertStringNotContainsString('/employees/1/delete', $body);
        assertStringContainsString('/employees/4/delete', $body);
    }
}
