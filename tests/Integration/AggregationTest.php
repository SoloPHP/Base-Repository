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

        // Create test table
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

    public function testSum(): void
    {
        $this->repository->create(['total' => 100, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 50, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 25, 'payment_status' => 'pending']);

        $sum = $this->repository->sum('total', ['payment_status' => 'paid']);

        $this->assertEquals(150, $sum);
    }

    public function testAvg(): void
    {
        $this->repository->create(['total' => 100, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 50, 'payment_status' => 'paid']);

        $avg = $this->repository->avg('total', ['payment_status' => 'paid']);

        $this->assertEquals(75, $avg);
    }

    public function testMin(): void
    {
        $this->repository->create(['total' => 100, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 50, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 25, 'payment_status' => 'paid']);

        $min = $this->repository->min('total', ['payment_status' => 'paid']);

        $this->assertEquals(25, $min);
    }

    public function testMax(): void
    {
        $this->repository->create(['total' => 100, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 50, 'payment_status' => 'paid']);
        $this->repository->create(['total' => 200, 'payment_status' => 'paid']);

        $max = $this->repository->max('total', ['payment_status' => 'paid']);

        $this->assertEquals(200, $max);
    }

    public function testAggregationWithSoftDelete(): void
    {
        $this->repository->create(['total' => 100, 'payment_status' => 'paid']);
        $deleted = $this->repository->create(['total' => 50, 'payment_status' => 'paid']);
        $this->repository->delete($deleted->id);

        $sum = $this->repository->sum('total', ['payment_status' => 'paid']);

        $this->assertEquals(100, $sum);
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
