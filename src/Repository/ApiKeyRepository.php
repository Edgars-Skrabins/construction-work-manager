<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use DateTimeImmutable;
use DateTimeZone;
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
                'EXPIRES_AT' => $this->toDatabase($expiresAt),
            ])
            ->execute();

        return $this->findByKeyId($keyId) ?? throw new LogicException('The inserted API key was not found.');
    }

    public function findByKeyId(string $keyId): ?ApiKey
    {
        /** @var array<string, int|string|null>|null $row */
        $row = (new Query($this->db))
            ->select(self::COLUMNS)
            ->from('API_KEY')
            ->where(['KEY_ID' => $keyId])
            ->one();

        return $row === null ? null : $this->toEntity($row);
    }

    public function findAll(): array
    {
        /** @var list<array<string, int|string|null>> $rows */
        $rows = (new Query($this->db))
            ->select(self::COLUMNS)
            ->from('API_KEY')
            ->orderBy(['CREATED_AT' => SORT_ASC, 'ID' => SORT_ASC])
            ->all();

        return array_map($this->toEntity(...), $rows);
    }

    public function revoke(string $keyId, DateTimeImmutable $at): bool
    {
        $time = $this->toDatabase($at);

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
            ->update('API_KEY', ['LAST_USED_AT' => $this->toDatabase($at)], ['ID' => $id])
            ->execute();
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function toEntity(array $row): ApiKey
    {
        return new ApiKey(
            id: (int) $row['ID'],
            keyId: (string) $row['KEY_ID'],
            clientName: (string) $row['CLIENT_NAME'],
            secretHash: (string) $row['SECRET_HASH'],
            createdAt: $this->fromDatabase($row['CREATED_AT']) ?? throw new LogicException('API key without a creation time.'),
            expiresAt: $this->fromDatabase($row['EXPIRES_AT']),
            lastUsedAt: $this->fromDatabase($row['LAST_USED_AT']),
            revokedAt: $this->fromDatabase($row['REVOKED_AT']),
        );
    }

    private function toDatabase(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function fromDatabase(int|string|null $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
