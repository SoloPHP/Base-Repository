<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

class BaseRepositoryTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private TestUserRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        // Create test table
        $this->connection->executeStatement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                status VARCHAR(50),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->repository = new TestUserRepository($this->connection);
    }

    protected function getConnection(): \Doctrine\DBAL\Connection
    {
        return $this->connection;
    }

    public function testCreate(): void
    {
        $user = $this->repository->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'status' => 'active',
        ]);

        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertNotNull($user->id);
    }

    public function testFindAndFindOneBy(): void
    {
        $created = $this->repository->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'status' => 'active',
        ]);

        // find()
        $found = $this->repository->find($created->id);
        $this->assertNotNull($found);
        $this->assertEquals($created->id, $found->id);

        // find() returns null when not found
        $this->assertNull($this->repository->find(999));

        // findOneBy()
        $user = $this->repository->findOneBy(['email' => 'jane@example.com']);
        $this->assertNotNull($user);
        $this->assertEquals('jane@example.com', $user->email);
    }

    public function testFindBy(): void
    {
        $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'active']);
        $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'active']);
        $this->repository->create(['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'inactive']);

        $users = $this->repository->findBy(['status' => 'active']);

        $this->assertCount(2, $users);
        foreach ($users as $user) {
            $this->assertEquals('active', $user->status);
        }
    }

    public function testFindByWithOrderBy(): void
    {
        $this->repository->create(['name' => 'B User', 'email' => 'b@example.com']);
        $this->repository->create(['name' => 'A User', 'email' => 'a@example.com']);
        $this->repository->create(['name' => 'C User', 'email' => 'c@example.com']);

        $users = $this->repository->findBy([], ['name' => 'ASC']);

        $this->assertCount(3, $users);
        $this->assertEquals('A User', $users[0]->name);
        $this->assertEquals('B User', $users[1]->name);
    }

    public function testFindByWithPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repository->create(['name' => "User $i", 'email' => "user$i@example.com"]);
        }

        $page1 = $this->repository->findBy([], null, 2, 1);
        $page2 = $this->repository->findBy([], null, 2, 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
    }

    public function testUpdate(): void
    {
        $user = $this->repository->create([
            'name' => 'John',
            'email' => 'john@example.com',
        ]);

        $updated = $this->repository->update($user->id, ['name' => 'John Updated']);

        $this->assertEquals('John Updated', $updated->name);
        $this->assertEquals('john@example.com', $updated->email);
    }

    public function testUpdateBy(): void
    {
        $user1 = $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'active']);
        $user2 = $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'active']);
        $user3 = $this->repository->create(['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'inactive']);

        $affected = $this->repository->updateBy(['status' => 'active'], ['status' => 'updated']);

        $this->assertEquals(2, $affected);

        // Verify by finding updated records
        $updated1 = $this->repository->find($user1->id);
        $updated2 = $this->repository->find($user2->id);

        $this->assertNotNull($updated1);
        $this->assertNotNull($updated2);
        $this->assertEquals('updated', $updated1->status);
        $this->assertEquals('updated', $updated2->status);

        // Verify user3 was not updated
        $user3After = $this->repository->find($user3->id);
        $this->assertNotNull($user3After);
        $this->assertEquals('inactive', $user3After->status);
    }

    public function testDelete(): void
    {
        $user = $this->repository->create([
            'name' => 'To Delete',
            'email' => 'delete@example.com',
        ]);

        $affected = $this->repository->delete($user->id);

        $this->assertEquals(1, $affected);

        $found = $this->repository->find($user->id);
        $this->assertNull($found);
    }

    public function testDeleteBy(): void
    {
        $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'active']);
        $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'active']);
        $this->repository->create(['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'inactive']);

        $affected = $this->repository->deleteBy(['status' => 'active']);

        $this->assertEquals(2, $affected);

        $remaining = $this->repository->findAll();
        $this->assertCount(1, $remaining);
    }

    public function testExists(): void
    {
        $this->repository->create(['name' => 'Test', 'email' => 'test@example.com']);

        $this->assertTrue($this->repository->exists(['email' => 'test@example.com']));
        $this->assertFalse($this->repository->exists(['email' => 'nonexistent@example.com']));
    }

    public function testCount(): void
    {
        $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'active']);
        $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'active']);
        $this->repository->create(['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'inactive']);

        $this->assertEquals(3, $this->repository->count([]));
        $this->assertEquals(2, $this->repository->count(['status' => 'active']));
    }

    public function testInsertMany(): void
    {
        $records = [
            ['name' => 'User 1', 'email' => 'user1@example.com'],
            ['name' => 'User 2', 'email' => 'user2@example.com'],
            ['name' => 'User 3', 'email' => 'user3@example.com'],
        ];

        $affected = $this->repository->insertMany($records);

        $this->assertEquals(3, $affected);
        $this->assertEquals(3, $this->repository->count([]));
    }

    public function testFindByWithCriteriaVariants(): void
    {
        $user1 = $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => null]);
        $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'active']);
        $user3 = $this->repository->create(['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'active']);

        // IN list
        $users = $this->repository->findBy(['id' => [$user3->id]]);
        $this->assertCount(1, $users);
        $this->assertEquals('User 3', $users[0]->name);

        // Null value
        $users = $this->repository->findBy(['status' => null]);
        $this->assertCount(1, $users);
        $this->assertNull($users[0]->status);
    }

    public function testFindByWithOperator(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL,
                views INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $postRepo = new TestPostRepository($this->connection);
        $postRepo->create(['title' => 'Post 1', 'views' => 10]);
        $postRepo->create(['title' => 'Post 2', 'views' => 20]);
        $postRepo->create(['title' => 'Post 3', 'views' => 5]);

        $this->assertCount(2, $postRepo->findBy(['views' => ['>=' => 10]]));
    }

    public function testTransactions(): void
    {
        // Basic transaction methods
        $this->assertFalse($this->repository->inTransaction());
        $this->repository->beginTransaction();
        $this->assertTrue($this->repository->inTransaction());
        $this->repository->commit();
        $this->assertFalse($this->repository->inTransaction());

        // withTransaction success
        $result = $this->repository->withTransaction(function ($repo) {
            $repo->create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $repo->create(['name' => 'User 2', 'email' => 'user2@example.com']);
            return 'success';
        });
        $this->assertEquals('success', $result);
        $this->assertEquals(2, $this->repository->count([]));

        // withTransaction rollback
        try {
            $this->repository->withTransaction(function ($repo) {
                $repo->create(['name' => 'Temp', 'email' => 'temp@example.com']);
                throw new \RuntimeException('Test rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals('Test rollback', $e->getMessage());
        }
        $this->assertEquals(2, $this->repository->count([])); // Still 2, temp not committed
    }

    public function testEdgeCases(): void
    {
        // Insert many with empty array
        $this->assertEquals(0, $this->repository->insertMany([]));

        // FindOneBy with orderBy
        $this->repository->create(['name' => 'B User', 'email' => 'b@example.com']);
        $this->repository->create(['name' => 'A User', 'email' => 'a@example.com']);
        $user = $this->repository->findOneBy([], ['name' => 'ASC']);
        $this->assertNotNull($user);
        $this->assertEquals('A User', $user->name);

        // No match returns zero
        $this->assertEquals(0, $this->repository->updateBy(['status' => 'nonexistent'], ['status' => 'updated']));
        $this->assertEquals(0, $this->repository->deleteBy(['status' => 'nonexistent']));
    }

    public function testQueryBuilderAccess(): void
    {
        $this->repository->create(['name' => 'User 1', 'email' => 'user1@example.com']);
        $this->repository->create(['name' => 'User 2', 'email' => 'user2@example.com']);

        $this->assertEquals(2, $this->repository->countWithCustomQuery());
    }
}

// Test model
class TestUser
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $status = null,
        public ?string $created_at = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'],
            $data['email'],
            $data['status'] ?? null,
            $data['created_at'] ?? null
        );
    }
}

// Test repository
class TestUserRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, TestUser::class, 'users');
    }

    public function countWithCustomQuery(): int
    {
        return (int) $this->queryBuilder()
            ->select('COUNT(*)')
            ->from('users')
            ->executeQuery()
            ->fetchOne();
    }
}

// Test post model
class TestPost
{
    public function __construct(
        public int $id,
        public string $title,
        public int $views = 0,
        public ?string $created_at = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['title'],
            $data['views'] ?? 0,
            $data['created_at'] ?? null
        );
    }
}

// Test post repository
class TestPostRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, TestPost::class, 'posts');
    }
}
