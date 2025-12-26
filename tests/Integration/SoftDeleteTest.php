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

    public function testSoftDeleteAndDeleteBy(): void
    {
        // Single soft delete
        $user = $this->repository->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $this->assertEquals(1, $this->repository->delete($user->id));
        $this->assertNull($this->repository->find($user->id));

        // deleteBy affects all matching records (including already soft-deleted)
        $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com']);
        $this->assertEquals(3, $this->repository->deleteBy([])); // All 3 records
        $this->assertEquals(0, $this->repository->count([]));
    }

    public function testQueriesExcludeSoftDeleted(): void
    {
        $this->repository->create(['name' => 'Active User', 'email' => 'active@example.com']);
        $deleted = $this->repository->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
        $this->repository->delete($deleted->id);

        // findAll excludes soft deleted
        $this->assertCount(1, $this->repository->findAll());

        // findBy excludes soft deleted
        $this->assertCount(1, $this->repository->findBy([]));

        // findOneBy excludes soft deleted
        $this->assertNull($this->repository->findOneBy(['email' => 'deleted@example.com']));

        // exists excludes soft deleted
        $this->assertFalse($this->repository->exists(['email' => 'deleted@example.com']));

        // count excludes soft deleted
        $this->assertEquals(1, $this->repository->count([]));
    }

    public function testRestoreAndForceDelete(): void
    {
        // Restore
        $user = $this->repository->create(['name' => 'User', 'email' => 'user@example.com']);
        $this->repository->delete($user->id);
        $this->assertNull($this->repository->find($user->id));
        $this->assertEquals(1, $this->repository->restore($user->id));
        $this->assertNotNull($this->repository->find($user->id));

        // Force delete (physical)
        $user2 = $this->repository->create(['name' => 'User2', 'email' => 'user2@example.com']);
        $this->assertEquals(1, $this->repository->forceDelete($user2->id));
        $result = $this->connection->executeQuery('SELECT * FROM soft_users WHERE id = ?', [$user2->id])->fetchAssociative();
        $this->assertFalse($result);

        // Force delete by
        $this->repository->create(['name' => 'User 3', 'email' => 'user3@example.com']);
        $this->repository->create(['name' => 'User 4', 'email' => 'user4@example.com']);
        $this->assertEquals(3, $this->repository->forceDeleteBy([])); // user + user3 + user4
        $this->assertEquals(0, $this->connection->executeQuery('SELECT COUNT(*) FROM soft_users')->fetchOne());
    }

    public function testFindDeletedRecordsDirectly(): void
    {
        $this->repository->create(['name' => 'Active User', 'email' => 'active@example.com']);
        $deleted = $this->repository->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
        $this->repository->delete($deleted->id);

        $users = $this->repository->findBy(['deleted_at' => ['!=' => null]]);
        $this->assertCount(1, $users);
        $this->assertEquals('Deleted User', $users[0]->name);
    }

    public function testRestoreOnNonSoftDeleteRepository(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE regular_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL
            )
        ');

        $regularRepo = new RegularUserRepository($this->connection);
        $user = $regularRepo->create(['name' => 'User']);
        $this->assertEquals(0, $regularRepo->restore($user->id));
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
