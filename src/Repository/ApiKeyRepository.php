<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use DateTimeImmutable;
use LogicException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final readonly class ApiKeyRepository implements ApiKeyRepositoryInterface
{
    private const array COLUMNS = [
        'ID',
        'KEY_ID',
        'CLIENT_NAME',
        'SECRET_HASH',
        'CREATED_AT',
        'EXPIRES_AT',
        'LAST_USED_AT',
        'REVOKED_AT',
    ];

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function insert(string $keyId, string $clientName, string $secretHash, ?DateTimeImmutable $expiresAt): ApiKey
    {
        $this->db->createCommand()
            ->insert('API_KEY', [
                'KEY_ID' => $keyId,
                'CLIENT_NAME' => $clientName,
                'SECRET_HASH' => $secretHash,
                'EXPIRES_AT' => DbTime::toUtc($expiresAt),
            ])
            ->execute();

        return $this->findByKeyId($keyId) ?? throw new LogicException('The inserted API key was not found.');
    }

    public function findByKeyId(string $keyId): ?ApiKey
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->db))
            ->select(self::COLUMNS)
            ->from('API_KEY')
            ->where(['KEY_ID' => $keyId])
            ->one();

        return $row === null ? null : $this->toEntity($row);
    }

    public function findAll(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->db))
            ->select(self::COLUMNS)
            ->from('API_KEY')
            ->orderBy(['CREATED_AT' => SORT_ASC, 'ID' => SORT_ASC])
            ->all();

        return array_map($this->toEntity(...), $rows);
    }

    public function revoke(string $keyId, DateTimeImmutable $at): bool
    {
        $time = DbTime::toUtc($at);

        return $this->db->createCommand()
            ->update(
                'API_KEY',
                ['REVOKED_AT' => $time, 'UPDATED_AT' => $time],
                ['KEY_ID' => $keyId, 'REVOKED_AT' => null],
            )
            ->execute() > 0;
    }

    public function touchLastUsed(int $id, DateTimeImmutable $at): void
    {
        $this->db->createCommand()
            ->update('API_KEY', ['LAST_USED_AT' => DbTime::toUtc($at)], ['ID' => $id])
            ->execute();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toEntity(array $row): ApiKey
    {
        /** @var array{ID: int|string, KEY_ID: string, CLIENT_NAME: string, SECRET_HASH: string, CREATED_AT: string, EXPIRES_AT: string|null, LAST_USED_AT: string|null, REVOKED_AT: string|null} $row */
        return new ApiKey(
            id: (int) $row['ID'],
            keyId: $row['KEY_ID'],
            clientName: $row['CLIENT_NAME'],
            secretHash: $row['SECRET_HASH'],
            createdAt: DbTime::fromUtc($row['CREATED_AT']),
            expiresAt: DbTime::fromUtc($row['EXPIRES_AT']),
            lastUsedAt: DbTime::fromUtc($row['LAST_USED_AT']),
            revokedAt: DbTime::fromUtc($row['REVOKED_AT']),
        );
    }
}
