<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Solo\BaseRepository\LockTimeoutException;

/**
 * Cross-process advisory (named) lock dispatched by DBAL platform.
 *
 * Unlike SELECT ... FOR UPDATE, an advisory lock does not require the target
 * row to exist and is held against an arbitrary resource name, which makes it
 * suitable for "run this critical section once per record" idempotency.
 *
 * The lock is server-/database-wide and shared across connections, so the
 * resource name must already encode every dimension that should not collide
 * (database, table, id) — see BaseRepository::lockResourceFor().
 *
 * @internal
 */
final class AdvisoryLock
{
    /** Poll interval for the PostgreSQL wait loop, in microseconds. */
    private const POLL_INTERVAL_US = 100_000;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Block until the lock is held or $timeout seconds elapse.
     *
     * @param int $timeout Seconds to wait before giving up
     * @throws LockTimeoutException when the lock is held by someone else past the timeout
     */
    public function acquire(string $resource, int $timeout): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->acquireMysql($resource, $timeout);
            return;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $this->acquirePostgres($resource, $timeout);
            return;
        }

        throw new \RuntimeException(
            'Advisory locking is not supported on platform ' . $platform::class . '.'
        );
    }

    /**
     * Release the lock. Best-effort: never throws, so it is safe in a finally block.
     */
    public function release(string $resource): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->connection->fetchOne(
                'SELECT RELEASE_LOCK(?)',
                [$this->mysqlName($resource)],
                [ParameterType::STRING]
            );
            return;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $this->connection->fetchOne(
                'SELECT pg_advisory_unlock(?)',
                [$this->postgresKey($resource)],
                [ParameterType::INTEGER]
            );
        }
    }

    private function acquireMysql(string $resource, int $timeout): void
    {
        $result = $this->connection->fetchOne(
            'SELECT GET_LOCK(?, ?)',
            [$this->mysqlName($resource), $timeout],
            [ParameterType::STRING, ParameterType::INTEGER]
        );

        // GET_LOCK returns 1 = acquired, 0 = timed out, NULL = error/deadlock.
        if ((int) $result === 1) {
            return;
        }

        if ($result === null) {
            throw new \RuntimeException(
                "Failed to acquire advisory lock for '{$resource}' (database error)."
            );
        }

        throw new LockTimeoutException(
            "Timed out after {$timeout}s waiting for advisory lock on '{$resource}'."
        );
    }

    private function acquirePostgres(string $resource, int $timeout): void
    {
        $key = $this->postgresKey($resource);

        // pg_try_advisory_lock is non-blocking; lock_timeout does not apply to
        // advisory functions, so we poll until the deadline ourselves.
        $deadline = microtime(true) + $timeout;
        while (true) {
            $acquired = $this->connection->fetchOne(
                'SELECT pg_try_advisory_lock(?)',
                [$key],
                [ParameterType::INTEGER]
            );
            if ($this->isTruthy($acquired)) {
                return;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(self::POLL_INTERVAL_US);
        }

        throw new LockTimeoutException(
            "Timed out after {$timeout}s waiting for advisory lock on '{$resource}'."
        );
    }

    /**
     * MySQL lock names must be <= 64 bytes (since 5.7.5); hashing keeps a stable,
     * collision-resistant length regardless of the resource string.
     */
    private function mysqlName(string $resource): string
    {
        return 'solo_lock:' . sha1($resource);
    }

    /**
     * PostgreSQL advisory locks key on a bigint. Take 60 bits of the digest so
     * the value always fits a signed 64-bit int without overflowing into float.
     */
    private function postgresKey(string $resource): int
    {
        return (int) hexdec(substr(sha1($resource), 0, 15));
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
