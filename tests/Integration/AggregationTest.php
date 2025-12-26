<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

class AggregationTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private TestOrderRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                total INTEGER NOT NULL,
                payment_status VARCHAR(50),
                deleted_at DATETIME DEFAULT NULL
            )
        ');

        $this->repository = new TestOrderRepository($this->connection);
    }

    public function testAggregations(): void
    {
        $this->repository->create(['total' => 100, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 50, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 25, 'payment_status' => 'pending']);

        $this->assertEquals(150, $this->repository->sum('total', ['payment_status' => 'paid']));
        $this->assertEquals(75, $this->repository->avg('total', ['payment_status' => 'paid']));
        $this->assertEquals(50, $this->repository->min('total', ['payment_status' => 'paid']));
        $this->assertEquals(100, $this->repository->max('total', ['payment_status' => 'paid']));
    }

    public function testAggregationWithSoftDelete(): void
    {
        $this->repository->create(['total' => 100, 'payment_status' => 'paid']);
        $deleted = $this->repository->create(['total' => 50, 'payment_status' => 'paid']);
        $this->repository->delete($deleted->id);

        $this->assertEquals(100, $this->repository->sum('total', ['payment_status' => 'paid']));
    }

    public function testAggregationsOnEmptyTable(): void
    {
        $this->assertEquals(0.0, $this->repository->sum('total'));
        $this->assertEquals(0.0, $this->repository->avg('total'));
        $this->assertNull($this->repository->min('total'));
        $this->assertNull($this->repository->max('total'));
        $this->assertEquals(0, $this->repository->count([]));
        $this->assertFalse($this->repository->exists([]));
    }

    public function testAggregationsWithOperators(): void
    {
        $this->repository->create(['total' => 100, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 50, 'payment_status' => 'pending']);
        $this->repository->create(['total' => 25, 'payment_status' => 'cancelled']);

        // Count with operator
        $this->assertEquals(2, $this->repository->count(['total' => ['>=' => 50]]));

        // Sum with IN operator
        $this->assertEquals(150, $this->repository->sum('total', ['payment_status' => ['IN' => ['paid', 'pending']]]));

        // No match
        $this->assertEquals(0.0, $this->repository->sum('total', ['payment_status' => 'nonexistent']));
    }
}

// Test model
class TestOrder
{
    public function __construct(
        public int $id,
        public int $total,
        public ?string $payment_status = null,
        public ?string $deleted_at = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (int) $data['total'],
            $data['payment_status'] ?? null,
            $data['deleted_at'] ?? null
        );
    }
}

// Test repository
class TestOrderRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, TestOrder::class, 'orders');
    }
}
