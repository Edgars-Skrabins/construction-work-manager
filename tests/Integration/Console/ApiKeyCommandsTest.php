<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Console\ApiKeyCreateCommand;
use App\Console\ApiKeyListCommand;
use App\Console\ApiKeyRevokeCommand;
use App\Service\ApiKeyService;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Yii\Console\ExitCode;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class ApiKeyCommandsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testCreatePrintsAKeyThatAuthenticates(): void
    {
        $tester = $this->tester(ApiKeyCreateCommand::class);

        $exitCode = $tester->execute(['client' => 'North Gate', '--expires' => '2099-01-31']);
        $display = $tester->getDisplay();

        assertSame(ExitCode::OK, $exitCode);
        assertStringContainsString('Client: North Gate', $display);
        assertStringContainsString('Expires: 2099-01-31 00:00 UTC', $display);
        assertStringContainsString("Store this key now. It can't be shown again.", $display);
        assertNotNull($this->service(ApiKeyService::class)->authenticate($this->plainKey($display)));
    }

    public function testCreateWithoutExpiryNeverExpires(): void
    {
        $tester = $this->tester(ApiKeyCreateCommand::class);

        $tester->execute(['client' => 'North Gate']);

        assertStringContainsString('Expires: never', $tester->getDisplay());
    }

    #[DataProvider('invalidCreates')]
    public function testInvalidCreateFails(string $client, ?string $expires, string $message): void
    {
        $tester = $this->tester(ApiKeyCreateCommand::class);
        $input = ['client' => $client];
        if ($expires !== null) {
            $input['--expires'] = $expires;
        }

        $exitCode = $tester->execute($input);

        assertSame(ExitCode::DATAERR, $exitCode);
        assertStringContainsString($message, $tester->getDisplay());
        assertStringNotContainsString('cwm_', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function invalidCreates(): iterable
    {
        yield 'blank client' => ['   ', null, 'must not be empty'];
        yield 'not a date' => ['North Gate', 'tomorrow', 'YYYY-MM-DD'];
        yield 'impossible date' => ['North Gate', '2099-02-30', 'YYYY-MM-DD'];
        yield 'past date' => ['North Gate', '2020-01-01', 'in the future'];
    }

    public function testListShowsKeysWithoutSecrets(): void
    {
        $create = $this->tester(ApiKeyCreateCommand::class);
        $create->execute(['client' => 'North Gate']);
        $plainKey = $this->plainKey($create->getDisplay());
        [, $keyId, $secret] = explode('_', $plainKey);

        $list = $this->tester(ApiKeyListCommand::class);
        $exitCode = $list->execute([]);
        $display = $list->getDisplay();

        assertSame(ExitCode::OK, $exitCode);
        assertMatchesRegularExpression('/' . $keyId . '\s+\|\s+North Gate\s+\|.*\|\s+active\s+\|/', $display);
        assertStringNotContainsString($secret, $display);
        assertStringNotContainsString(hash('sha256', $secret), $display);
    }

    public function testListWithoutKeys(): void
    {
        $this->db()->createCommand()->delete('API_KEY')->execute();
        $tester = $this->tester(ApiKeyListCommand::class);

        $tester->execute([]);

        assertSame("No API keys.\n", $tester->getDisplay());
    }

    public function testRevokedKeyNoLongerAuthenticates(): void
    {
        $create = $this->tester(ApiKeyCreateCommand::class);
        $create->execute(['client' => 'North Gate']);
        $plainKey = $this->plainKey($create->getDisplay());
        $keyId = explode('_', $plainKey)[1];

        $revoke = $this->tester(ApiKeyRevokeCommand::class);
        $exitCode = $revoke->execute(['key-id' => $keyId]);

        assertSame(ExitCode::OK, $exitCode);
        assertSame("Revoked.\n", $revoke->getDisplay());
        assertNull($this->service(ApiKeyService::class)->authenticate($plainKey));

        $list = $this->tester(ApiKeyListCommand::class);
        $list->execute([]);
        assertMatchesRegularExpression('/' . $keyId . '.*\|\s+revoked\s+\|/', $list->getDisplay());

        assertSame(ExitCode::DATAERR, $revoke->execute(['key-id' => $keyId]));
    }

    public function testRevokeUnknownKeyIdFails(): void
    {
        $tester = $this->tester(ApiKeyRevokeCommand::class);

        assertSame(ExitCode::DATAERR, $tester->execute(['key-id' => 'ffffffff']));
        assertStringContainsString('No active API key with this key ID.', $tester->getDisplay());
    }

    /**
     * @param class-string<ApiKeyCreateCommand|ApiKeyListCommand|ApiKeyRevokeCommand> $command
     */
    private function tester(string $command): CommandTester
    {
        return new CommandTester($this->service($command));
    }

    private function plainKey(string $display): string
    {
        assertMatchesRegularExpression('/^Key: (cwm_[0-9a-f]{8}_[0-9a-f]{64})$/m', $display);
        preg_match('/^Key: (cwm_[0-9a-f]{8}_[0-9a-f]{64})$/m', $display, $matches);

        return $matches[1] ?? '';
    }
}
