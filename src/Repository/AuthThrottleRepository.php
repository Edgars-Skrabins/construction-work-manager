<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuthThrottle;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final readonly class AuthThrottleRepository implements AuthThrottleRepositoryInterface
{
    private const string RECORD_FAILURE = <<<'SQL'
        MERGE AUTH_THROTTLE WITH (HOLDLOCK) AS target
        USING (SELECT :scope AS SCOPE, :keyHash AS KEY_HASH) AS source
        ON target.SCOPE = source.SCOPE AND target.KEY_HASH = source.KEY_HASH
        WHEN MATCHED THEN
            UPDATE SET
                FAILURES = CASE WHEN target.WINDOW_START <= :resetFailuresBefore THEN 1 ELSE target.FAILURES + 1 END,
                WINDOW_START = CASE WHEN target.WINDOW_START <= :resetWindowBefore THEN :now ELSE target.WINDOW_START END
        WHEN NOT MATCHED THEN
            INSERT (SCOPE, KEY_HASH, FAILURES, WINDOW_START) VALUES (source.SCOPE, source.KEY_HASH, 1, :nowInsert);
        SQL;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $scope, string $keyHash): ?AuthThrottle
    {
        /** @var array{FAILURES: int|string, WINDOW_START: string}|null $row */
        $row = (new Query($this->db))
            ->select(['FAILURES', 'WINDOW_START'])
            ->from('AUTH_THROTTLE')
            ->where(['SCOPE' => $scope, 'KEY_HASH' => $keyHash])
            ->one();

        return $row === null ? null : new AuthThrottle((int) $row['FAILURES'], DbTime::fromUtc($row['WINDOW_START']));
    }

    public function recordFailure(string $scope, string $keyHash, DateTimeImmutable $now, DateTimeImmutable $windowStartsAfter): void
    {
        $time = DbTime::toUtc($now);
        $cutoff = DbTime::toUtc($windowStartsAfter);

        $this->db->createCommand(self::RECORD_FAILURE, [
            ':scope' => $scope,
            ':keyHash' => $keyHash,
            ':resetFailuresBefore' => $cutoff,
            ':resetWindowBefore' => $cutoff,
            ':now' => $time,
            ':nowInsert' => $time,
        ])->execute();

        $this->db->createCommand('DELETE FROM AUTH_THROTTLE WHERE WINDOW_START <= :cutoff', [':cutoff' => $cutoff])->execute();
    }

    public function clear(string $scope, string $keyHash): void
    {
        $this->db->createCommand()
            ->delete('AUTH_THROTTLE', ['SCOPE' => $scope, 'KEY_HASH' => $keyHash])
            ->execute();
    }
}
