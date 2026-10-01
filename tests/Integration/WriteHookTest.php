<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\BelongsToMany;

class WriteHookTest extends TestCase
{
    private Connection $connection;
    private WriteHookRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->connection->executeStatement('
            CREATE TABLE hook_organizations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                timezone TEXT NOT NULL DEFAULT \'UTC\',
                deleted_at TEXT DEFAULT NULL
            )
        ');
        $this->connection->insert('hook_organizations', ['id' => 1, 'name' => 'Original']);
        $this->repository = new WriteHookRepository($this->connection);
    }

    public static function writeMethods(): iterable
    {
        yield 'insert' => ['insert', [['name' => 'Inserted']], false];
        yield 'create' => ['create', [['name' => 'Created']], false];
        yield 'insertMany' => ['insertMany', [[['name' => 'First'], ['name' => 'Second']]], false];
        yield 'update' => ['update', [1, ['timezone' => 'Europe/Paris']], false];
        yield 'updateBy' => ['updateBy', [['id' => 1], ['timezone' => 'Europe/Paris']], false];
        yield 'forceDelete' => ['forceDelete', [1], false];
        yield 'forceDeleteBy' => ['forceDeleteBy', [['id' => 1]], false];
        yield 'hard delete' => ['delete', [1], false];
        yield 'hard deleteBy' => ['deleteBy', [['id' => 1]], false];
        yield 'soft delete' => ['delete', [1], true];
        yield 'soft deleteBy' => ['deleteBy', [['id' => 1]], true];
        yield 'restore' => ['restore', [1], true];
    }

    #[DataProvider('writeMethods')]
    public function testWritesCallHookOnce(string $method, array $args, bool $softDelete): void
    {
        $repository = new WriteHookRepository($this->connection, $softDelete);
        if ($method === 'restore') {
            $this->connection->update('hook_organizations', ['deleted_at' => '2026-01-01'], ['id' => 1]);
        }

        $repository->{$method}(...$args);

        self::assertSame(1, $repository->writeCalls);
    }

    public function testInsertManyCallsHookForEachChunk(): void
    {
        $records = array_map(static fn(int $id): array => ['name' => 'Organization ' . $id], range(1, 501));

        self::assertSame(501, $this->repository->insertMany($records));
        self::assertSame(2, $this->repository->writeCalls);
        self::assertSame(502, $this->repository->count([]));
    }

    public function testInsertManyCallsHookForEachColumnGroup(): void
    {
        self::assertSame(3, $this->repository->insertMany([
            ['name' => 'Default timezone'],
            ['name' => 'Explicit timezone', 'timezone' => 'Europe/Paris'],
            ['name' => 'Another default timezone'],
        ]));

        self::assertSame(2, $this->repository->writeCalls);
    }

    public function testPartialInsertManyFailureStillInvalidatesMemo(): void
    {
        self::assertSame('UTC', $this->repository->timezone(1));
        $records = array_map(static fn(int $id): array => ['name' => 'Organization ' . $id], range(1, 500));
        $records[] = ['name' => 'Original'];

        try {
            $this->repository->insertMany($records);
            self::fail('Expected the second chunk to violate the unique constraint.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(501, $this->repository->count([]));
            self::assertSame(1, $this->repository->writeCalls);
            self::assertSame('UTC', $this->repository->timezone(1));
            self::assertSame(2, $this->repository->timezoneReads);
        }
    }

    public static function failedWrites(): iterable
    {
        yield 'insert' => ['insert', [['name' => 'Original']]];
        yield 'create' => ['create', [['name' => 'Original']]];
        yield 'insertMany first statement' => ['insertMany', [[['name' => 'Original']]]];
        yield 'update' => ['update', [2, ['name' => 'Original']]];
        yield 'updateBy' => ['updateBy', [['id' => 2], ['name' => 'Original']]];
    }

    #[DataProvider('failedWrites')]
    public function testFailedStatementDoesNotCallHook(string $method, array $args): void
    {
        $this->connection->insert('hook_organizations', ['id' => 2, 'name' => 'Other']);

        try {
            $this->repository->{$method}(...$args);
            self::fail('Expected a unique constraint violation.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(0, $this->repository->writeCalls);
            self::assertSame(2, $this->repository->count([]));
        }
    }

    public static function zeroRowWrites(): iterable
    {
        yield 'updateBy' => ['updateBy', [['id' => 999], ['timezone' => 'UTC']]];
        yield 'forceDelete' => ['forceDelete', [999]];
        yield 'forceDeleteBy' => ['forceDeleteBy', [['id' => 999]]];
        yield 'soft delete' => ['delete', [999]];
        yield 'soft deleteBy' => ['deleteBy', [['id' => 999]]];
        yield 'restore' => ['restore', [999]];
    }

    #[DataProvider('zeroRowWrites')]
    public function testSuccessfulStatementWithNoMatchingRowsCallsHook(string $method, array $args): void
    {
        $repository = new WriteHookRepository($this->connection, true);

        self::assertSame(0, $repository->{$method}(...$args));
        self::assertSame(1, $repository->writeCalls);
    }

    public function testUnchangedUpdateCallsHook(): void
    {
        $this->repository->updateBy(['id' => 1], ['timezone' => 'UTC']);

        self::assertSame(1, $this->repository->writeCalls);
    }

    public function testMethodsWithoutAStatementDoNotCallHook(): void
    {
        self::assertSame(0, $this->repository->insertMany([]));
        self::assertSame(0, $this->repository->restore(1));
        self::assertSame(0, $this->repository->writeCalls);
    }

    public function testReadsDoNotCallHook(): void
    {
        self::assertNotNull($this->repository->find(1));
        self::assertNotNull($this->repository->findOneBy(['id' => 1]));
        self::assertCount(1, $this->repository->findBy([]));
        self::assertCount(1, $this->repository->findAll());
        self::assertSame(1, $this->repository->count([]));
        self::assertTrue($this->repository->exists(['id' => 1]));
        self::assertEquals(1, $this->repository->sum('id'));
        self::assertEquals(1, $this->repository->avg('id'));
        self::assertEquals(1, $this->repository->min('id'));
        self::assertEquals(1, $this->repository->max('id'));
        self::assertSame(0, $this->repository->writeCalls);
    }

    public function testWritesToOtherTablesDoNotCallHook(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE hook_translations (
                organization_id INTEGER NOT NULL,
                locale TEXT NOT NULL,
                name TEXT,
                PRIMARY KEY (organization_id, locale)
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE hook_peers (
                organization_id INTEGER NOT NULL,
                peer_id INTEGER NOT NULL,
                PRIMARY KEY (organization_id, peer_id)
            )
        ');
        $repository = new WriteHookRelatedRepository($this->connection);

        self::assertSame(1, $repository->seedTranslations(1, ['fr'], ['name' => 'Traduction']));
        $repository->attach('peers', 1, [2, 3]);
        $repository->detach('peers', 1, [2]);
        $repository->sync('peers', 1, [4]);

        self::assertSame([4], $this->connection->fetchFirstColumn('SELECT peer_id FROM hook_peers'));
        self::assertSame(0, $repository->writeCalls);
    }

    public static function writesWithFollowUpReads(): iterable
    {
        yield 'create' => ['create', [['name' => 'Created']], 2];
        yield 'update' => ['update', [1, ['name' => 'Updated']], 1];
    }

    #[DataProvider('writesWithFollowUpReads')]
    public function testHookRunsBeforeAFailingFollowUpRead(string $method, array $args, int $id): void
    {
        $repository = new WriteHookUnreadableRepository($this->connection);

        try {
            $repository->{$method}(...$args);
            self::fail('Expected the follow-up read to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Follow-up read failed', $exception->getMessage());
            self::assertSame(1, $repository->writeCalls);
            self::assertSame(1, $repository->callsSeenByRead);
            self::assertSame(
                $method === 'create' ? 'Created' : 'Updated',
                $this->connection->fetchOne('SELECT name FROM hook_organizations WHERE id = ?', [$id]),
            );
        }
    }

    public function testWriteInvalidatesMemoizedTimezone(): void
    {
        self::assertSame('UTC', $this->repository->timezone(1));
        self::assertSame('UTC', $this->repository->timezone(1));
        self::assertSame(1, $this->repository->timezoneReads);

        $this->repository->updateBy(['id' => 1], ['timezone' => 'Europe/Paris']);

        self::assertSame('Europe/Paris', $this->repository->timezone(1));
        self::assertSame('Europe/Paris', $this->repository->timezone(1));
        self::assertSame(2, $this->repository->timezoneReads);
    }

    public static function transactionOutcomes(): iterable
    {
        yield 'commit' => [true, 'Europe/Paris'];
        yield 'rollback' => [false, 'UTC'];
    }

    #[DataProvider('transactionOutcomes')]
    public function testMemoRemainsCorrectAfterTransaction(bool $commit, string $expected): void
    {
        self::assertSame('UTC', $this->repository->timezone(1));
        $this->repository->beginTransaction();
        self::assertSame('UTC', $this->repository->timezone(1));
        self::assertSame(2, $this->repository->timezoneReads);

        $this->repository->updateBy(['id' => 1], ['timezone' => 'Europe/Paris']);

        self::assertSame(1, $this->repository->writeCalls);
        self::assertTrue($this->repository->inTransaction());
        self::assertSame('Europe/Paris', $this->repository->timezone(1));
        self::assertSame('Europe/Paris', $this->repository->timezone(1));
        self::assertSame(4, $this->repository->timezoneReads);

        if ($commit) {
            $this->repository->commit();
        } else {
            $this->repository->rollBack();
        }

        self::assertSame($expected, $this->repository->timezone(1));
        self::assertSame($expected, $this->repository->timezone(1));
        self::assertSame(5, $this->repository->timezoneReads);
        self::assertSame(1, $this->repository->writeCalls);
    }

    public function testMemoRemainsCorrectAfterWithTransactionRollsBack(): void
    {
        self::assertSame('UTC', $this->repository->timezone(1));

        try {
            $this->repository->withTransaction(function (): void {
                $this->repository->updateBy(['id' => 1], ['timezone' => 'Europe/Paris']);
                self::assertSame('Europe/Paris', $this->repository->timezone(1));
                throw new \RuntimeException('Abort transaction');
            });
            self::fail('Expected the transaction to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Abort transaction', $exception->getMessage());
        }

        self::assertFalse($this->repository->inTransaction());
        self::assertSame('UTC', $this->repository->timezone(1));
        self::assertSame('UTC', $this->repository->timezone(1));
        self::assertSame(3, $this->repository->timezoneReads);
        self::assertSame(1, $this->repository->writeCalls);
    }
}

class WriteHookOrganization
{
    public function __construct(public int $id, public string $name, public string $timezone)
    {
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], $data['name'], $data['timezone']);
    }
}

/** @extends BaseRepository<WriteHookOrganization> */
class WriteHookRepository extends BaseRepository
{
    public int $writeCalls = 0;
    public int $timezoneReads = 0;
    private array $timezones = [];

    public function __construct(Connection $connection, bool $softDelete = false)
    {
        $this->deletedAtColumn = $softDelete ? 'deleted_at' : null;
        parent::__construct($connection, WriteHookOrganization::class, 'hook_organizations');
    }

    public function timezone(int $id): string
    {
        if ($this->inTransaction()) {
            return $this->readTimezone($id);
        }

        return $this->timezones[$id] ??= $this->readTimezone($id);
    }

    private function readTimezone(int $id): string
    {
        $this->timezoneReads++;
        return $this->find($id)?->timezone ?? throw new \RuntimeException('Organization not found');
    }

    protected function afterWrite(): void
    {
        $this->writeCalls++;
        $this->timezones = [];
    }
}

class WriteHookRelatedRepository extends WriteHookRepository
{
    protected ?array $translationConfig = [
        'table' => 'hook_translations',
        'foreignKey' => 'organization_id',
        'fields' => ['name'],
    ];

    public WriteHookRepository $peerRepository;

    public function __construct(Connection $connection)
    {
        $this->peerRepository = $this;
        $this->relationConfig = [
            'peers' => new BelongsToMany(
                repository: 'peerRepository',
                pivot: 'hook_peers',
                foreignPivotKey: 'organization_id',
                relatedPivotKey: 'peer_id',
            ),
        ];
        parent::__construct($connection);
    }
}

class WriteHookUnreadableRepository extends WriteHookRepository
{
    public ?int $callsSeenByRead = null;

    public function find(int|string $id): ?object
    {
        $this->callsSeenByRead = $this->writeCalls;
        throw new \RuntimeException('Follow-up read failed');
    }
}
