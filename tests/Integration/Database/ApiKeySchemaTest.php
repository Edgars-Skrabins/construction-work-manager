<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Db\Exception\Exception as DbException;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class ApiKeySchemaTest extends IntegrationTestCase
{
    private const string HASH = 'a3f1c2b4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f80';

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
        $this->insert('0a1b2c3d', 'North Gate', self::HASH);
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testValidRowIsStoredWithDefaults(): void
    {
        $row = $this->db()->createCommand(
            "SELECT CLIENT_NAME, EXPIRES_AT, LAST_USED_AT, REVOKED_AT, CREATED_AT FROM API_KEY WHERE KEY_ID = N'0a1b2c3d'",
        )->queryOne();

        assertSame('North Gate', $row['CLIENT_NAME'] ?? null);
        assertNull($row['EXPIRES_AT']);
        assertNull($row['LAST_USED_AT']);
        assertNull($row['REVOKED_AT']);
        assertNotNull($row['CREATED_AT']);
    }

    #[DataProvider('invalidRows')]
    public function testInvalidRowIsRejected(string $keyId, string $clientName, string $secretHash, string $constraint): void
    {
        try {
            $this->insert($keyId, $clientName, $secretHash);
            self::fail("Expected $constraint to reject the row.");
        } catch (DbException $e) {
            assertStringContainsString($constraint, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalidRows(): iterable
    {
        yield 'duplicate key ID' => ['0a1b2c3d', 'Other', self::HASH, 'UQ_API_KEY_KEY_ID'];
        yield 'uppercase key ID' => ['0A1B2C3E', 'Other', self::HASH, 'CK_API_KEY_KEY_ID'];
        yield 'short key ID' => ['0a1b2c3', 'Other', self::HASH, 'CK_API_KEY_KEY_ID'];
        yield 'non-hex key ID' => ['0a1b2c3g', 'Other', self::HASH, 'CK_API_KEY_KEY_ID'];
        yield 'blank client name' => ['0a1b2c3e', '   ', self::HASH, 'CK_API_KEY_CLIENT_NAME'];
        yield 'short hash' => ['0a1b2c3e', 'Other', substr(self::HASH, 1), 'CK_API_KEY_SECRET_HASH'];
        yield 'uppercase hash' => ['0a1b2c3e', 'Other', strtoupper(self::HASH), 'CK_API_KEY_SECRET_HASH'];
    }

    private function insert(string $keyId, string $clientName, string $secretHash): void
    {
        $this->db()->createCommand()->insert('API_KEY', [
            'KEY_ID' => $keyId,
            'CLIENT_NAME' => $clientName,
            'SECRET_HASH' => $secretHash,
        ])->execute();
    }
}
