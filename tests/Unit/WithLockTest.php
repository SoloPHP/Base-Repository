<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\LockTimeoutException;

class WithLockTest extends TestCase
{
    /** @var list<array{sql: string, params: array<int, mixed>}> */
    private array $calls = [];

    /**
     * Build a repository whose Connection records every fetchOne() and returns
     * $acquireResult for the acquire query (GET_LOCK / pg_try_advisory_lock).
     */
    private function makeRepo(
        AbstractPlatform $platform,
        mixed $acquireResult = 1,
        string $table = 'users',
        string $database = 'shop'
    ): BaseRepository {
        $this->calls = [];

        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getDatabase')->willReturn($database);
        $connection->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params = []) use ($acquireResult) {
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'pg_try_advisory_lock')) {
                    return $acquireResult;
                }

                return '1'; // RELEASE_LOCK / pg_advisory_unlock
            }
        );

        return new class ($connection, $table) extends BaseRepository {
            public function __construct(Connection $connection, string $table)
            {
                parent::__construct($connection, \stdClass::class, $table);
            }
        };
    }

    /** @return list<string> */
    private function executedSql(): array
    {
        return array_map(static fn(array $c): string => $c['sql'], $this->calls);
    }

    private function lockNameFor(string $table, int|string $id): string
    {
        $repo = $this->makeRepo(new MySQLPlatform(), 1, $table);
        $repo->withLock($id, static fn() => null);

        return (string) $this->calls[0]['params'][0];
    }

    public function testAcquiresRunsCallbackAndReleases(): void
    {
        $repo = $this->makeRepo(new MySQLPlatform(), acquireResult: 1);

        $result = $repo->withLock(42, static fn() => 'done');

        $this->assertSame('done', $result);

        $sql = $this->executedSql();
        $this->assertCount(2, $sql);
        $this->assertStringContainsString('GET_LOCK', $sql[0]);
        $this->assertStringContainsString('RELEASE_LOCK', $sql[1]);
        // Default timeout is forwarded to GET_LOCK.
        $this->assertSame(10, $this->calls[0]['params'][1]);
    }

    public function testReleasesLockWhenCallbackThrows(): void
    {
        $repo = $this->makeRepo(new MySQLPlatform(), 1);

        $caught = null;
        try {
            $repo->withLock(42, static function (): void {
                throw new \DomainException('boom');
            });
        } catch (\DomainException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(\DomainException::class, $caught);
        $this->assertSame('boom', $caught->getMessage());

        // Lock acquired, then released despite the exception (finally).
        $sql = $this->executedSql();
        $this->assertStringContainsString('GET_LOCK', $sql[0]);
        $this->assertStringContainsString('RELEASE_LOCK', $sql[1] ?? '');
    }

    public function testTimeoutThrowsSkipsCallbackAndDoesNotRelease(): void
    {
        // GET_LOCK returns 0 => lock held by someone else, timed out.
        $repo = $this->makeRepo(new MySQLPlatform(), 0);

        $ran = false;
        $caught = null;
        try {
            $repo->withLock(42, static function () use (&$ran): string {
                $ran = true;
                return 'never';
            }, 3);
        } catch (LockTimeoutException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(LockTimeoutException::class, $caught);
        $this->assertFalse($ran, 'callback must not run when the lock is not acquired');

        // Nothing to release if we never acquired the lock.
        foreach ($this->executedSql() as $sql) {
            $this->assertStringNotContainsString('RELEASE_LOCK', $sql);
        }
    }

    public function testLockNameIsolatesByTableAndIdAndIsDeterministic(): void
    {
        $usersOne = $this->lockNameFor('users', 1);
        $usersTwo = $this->lockNameFor('users', 2);
        $ordersOne = $this->lockNameFor('orders', 1);
        $usersOneAgain = $this->lockNameFor('users', 1);

        $this->assertNotSame($usersOne, $usersTwo, 'different id must not collide');
        $this->assertNotSame($usersOne, $ordersOne, 'different table must not collide');
        $this->assertNotSame($usersTwo, $ordersOne);
        $this->assertSame($usersOne, $usersOneAgain, 'same (table, id) must be stable');
    }

    public function testUnsupportedPlatformThrowsAndSkipsCallback(): void
    {
        $repo = $this->makeRepo(new SQLitePlatform(), 1);

        $ran = false;
        $caught = null;
        try {
            $repo->withLock(1, static function () use (&$ran): void {
                $ran = true;
            });
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        // A genuine "unsupported" error, not a timeout.
        $this->assertNotInstanceOf(LockTimeoutException::class, $caught);
        $this->assertStringContainsString('not supported', $caught->getMessage());
        $this->assertFalse($ran);
        $this->assertSame([], $this->calls, 'no SQL should be issued on an unsupported platform');
    }

    public function testPostgresAcquiresAndReleases(): void
    {
        $repo = $this->makeRepo(new PostgreSQLPlatform(), acquireResult: true);

        $result = $repo->withLock(7, static fn() => 'ok');

        $this->assertSame('ok', $result);

        $sql = $this->executedSql();
        $this->assertStringContainsString('pg_try_advisory_lock', $sql[0]);
        $this->assertStringContainsString('pg_advisory_unlock', $sql[1]);
        // PostgreSQL keys on a bigint.
        $this->assertIsInt($this->calls[0]['params'][0]);
    }
}
