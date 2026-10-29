<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class ManagerTeamPagesTest extends IntegrationTestCase
{
    use WebClient;

    private const array FIRST_TEAM = ['Liepiņš, Mārtiņš', 'Krūmiņa, Laura', 'Vītols, Kristaps'];
    private const array SECOND_TEAM = ['Zariņa, Anna', 'Kalējs, Roberts'];

    protected function tearDown(): void
    {
        $this->resetServices();
    }

    public function testManagerSeesOnlyTheirSubordinatesReadOnly(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $response = $this->request('GET', '/employees');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('href="/employees">Employees</a>', $body);
        foreach (self::FIRST_TEAM as $name) {
            assertStringContainsString("<td>$name</td>", $body);
        }
        foreach ([...self::SECOND_TEAM, 'Bērziņš, Jānis', 'Kalniņa, Ilze', 'Ozoliņš, Andris'] as $name) {
            assertStringNotContainsString("<td>$name</td>", $body);
        }
        assertStringNotContainsString('href="/employees/create"', $body);
        assertStringNotContainsString('/edit"', $body);
        assertStringNotContainsString('/delete"', $body);
    }

    public function testOtherManagerSeesOnlyTheirOwnTeam(): void
    {
        $this->logIn('manager2@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees?sort=-name')->getBody();

        foreach (self::SECOND_TEAM as $name) {
            assertStringContainsString("<td>$name</td>", $body);
        }
        foreach (self::FIRST_TEAM as $name) {
            assertStringNotContainsString("<td>$name</td>", $body);
        }
    }

    public function testManagerStillCannotOpenTheAdminActions(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        foreach (['/employees/create', '/employees/4/edit', '/employees/4/delete', '/employees/4/setup-code'] as $path) {
            assertSame(403, $this->request('GET', $path)->getStatusCode(), $path);
        }
    }

    public function testEmployeeCannotOpenTheEmployeesList(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        assertSame(403, $this->request('GET', '/employees')->getStatusCode());
        assertStringNotContainsString('href="/employees"', (string) $this->request('GET', '/my-assignments')->getBody());
    }
}
