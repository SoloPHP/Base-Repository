<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

class SoftDeleteTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private SoftDeleteUserRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE soft_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                deleted_at DATETIME DEFAULT NULL
            )
        ');

        $this->repository = new SoftDeleteUserRepository($this->connection);
    }

    public function testSoftDelete(): void
    {
        $user = $this->repository->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $affected = $this->repository->delete($user->id);

        $this->assertEquals(1, $affected);

        // Should not find soft deleted record
        $found = $this->repository->find($user->id);
        $this->assertNull($found);
    }

    public function testSoftDeleteBy(): void
    {
        $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $affected = $this->repository->deleteBy([]);

        $this->assertEquals(2, $affected);
        $this->assertEquals(0, $this->repository->count([]));
    }

    public function testFindAllExcludesSoftDeleted(): void
    {
        $this->repository->create(['name' => 'Active User', 'email' => 'active@example.com']);
        $deleted = $this->repository->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
        $this->repository->delete($deleted->id);

        $users = $this->repository->findAll();

        $this->assertCount(1, $users);
        $this->assertEquals('Active User', $users[0]->name);
    }

    public function testFindByExcludesSoftDeleted(): void
    {
        $this->repository->create(['name' => 'Active User', 'email' => 'active@example.com']);
        $deleted = $this->repository->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
        $this->repository->delete($deleted->id);

        $users = $this->repository->findBy([]);

        $this->assertCount(1, $users);
    }

    public function testFindOneByExcludesSoftDeleted(): void
    {
        $deleted = $this->repository->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
        $this->repository->delete($deleted->id);

        $found = $this->repository->findOneBy(['email' => 'deleted@example.com']);

        $this->assertNull($found);
    }

    public function testExistsExcludesSoftDeleted(): void
    {
        $deleted = $this->repository->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
        $this->repository->delete($deleted->id);

        $this->assertFalse($this->repository->exists(['email' => 'deleted@example.com']));
    }

    public function testCountExcludesSoftDeleted(): void
    {
        $this->repository->create(['name' => 'Active User', 'email' => 'active@example.com']);
        $deleted = $this->repository->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
        $this->repository->delete($deleted->id);

        $this->assertEquals(1, $this->repository->count([]));
    }

    public function testRestore(): void
    {
        $user = $this->repository->create(['name' => 'User', 'email' => 'user@example.com']);
        $this->repository->delete($user->id);

        // Should not find soft deleted record
        $this->assertNull($this->repository->find($user->id));

        // Restore
        $affected = $this->repository->restore($user->id);

        $this->assertEquals(1, $affected);

        // Should find restored record
        $found = $this->repository->find($user->id);
        $this->assertNotNull($found);
        $this->assertEquals('User', $found->name);
    }

    public function testForceDelete(): void
    {
        $user = $this->repository->create(['name' => 'User', 'email' => 'user@example.com']);

        $affected = $this->repository->forceDelete($user->id);

        $this->assertEquals(1, $affected);

        // Verify record is physically deleted (not just soft deleted)
        $result = $this->connection->executeQuery(
            'SELECT * FROM soft_users WHERE id = ?',
            [$user->id]
        )->fetchAssociative();

        $this->assertFalse($result);
    }

    public function testForceDeleteBy(): void
    {
        $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $affected = $this->repository->forceDeleteBy([]);

        $this->assertEquals(2, $affected);

        // Verify records are physically deleted
        $count = $this->connection->executeQuery('SELECT COUNT(*) FROM soft_users')->fetchOne();
        $this->assertEquals(0, $count);
    }

    public function testFindDeletedRecordsDirectly(): void
    {
        $this->repository->create(['name' => 'Active User', 'email' => 'active@example.com']);
        $deleted = $this->repository->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
        $this->repository->delete($deleted->id);

        // Find only deleted records using direct column filter
        $users = $this->repository->findBy(['deleted_at' => ['!=', null]]);

        $this->assertCount(1, $users);
        $this->assertEquals('Deleted User', $users[0]->name);
    }

    public function testRestoreOnNonSoftDeleteRepository(): void
    {
        // Create a repository without soft delete
        $this->connection->executeStatement('
            CREATE TABLE regular_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL
            )
        ');

        $regularRepo = new RegularUserRepository($this->connection);
        $user = $regularRepo->create(['name' => 'User']);

        // Restore should return 0 on non-soft-delete repository
        $affected = $regularRepo->restore($user->id);
        $this->assertEquals(0, $affected);
    }
}

class SoftDeleteUser
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $deleted_at = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['name'],
            $data['email'],
            $data['deleted_at'] ?? null
        );
    }
}

class SoftDeleteUserRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, SoftDeleteUser::class, 'soft_users');
    }
}

class RegularUser
{
    public function __construct(
        public int $id,
        public string $name
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], $data['name']);
    }
}

class RegularUserRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, RegularUser::class, 'regular_users');
    }
}
