<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

class ErrorHandlingTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private SimpleItemRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10, 2) NOT NULL
            )
        ');

        $this->repository = new SimpleItemRepository($this->connection);
    }

    public function testUnsafeIdentifierInCriteriaThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        $this->repository->findBy(['1=1; DROP TABLE items; --' => 'test']);
    }

    public function testUnsafeIdentifierWithSpaceThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        $this->repository->findBy(['field name' => 'test']);
    }

    public function testUnsafeIdentifierWithQuoteThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        $this->repository->findBy(["field'name" => 'test']);
    }

    public function testUnsafeOperatorThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe operator');

        $this->repository->findBy(['name' => ['DROP', 'value']]);
    }

    public function testUpdateNonExistentRecordThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Updated record not found');

        $this->repository->update(999, ['name' => 'Updated']);
    }

    public function testDeleteNonExistentRecordReturnsZero(): void
    {
        $affected = $this->repository->delete(999);

        $this->assertEquals(0, $affected);
    }

    public function testFindNonExistentRecordReturnsNull(): void
    {
        $result = $this->repository->find(999);

        $this->assertNull($result);
    }

    public function testFindOneByNonMatchingCriteriaReturnsNull(): void
    {
        $this->repository->create(['name' => 'Test', 'price' => 10.00]);

        $result = $this->repository->findOneBy(['name' => 'NonExistent']);

        $this->assertNull($result);
    }

    public function testValidIdentifiersWork(): void
    {
        $this->repository->create(['name' => 'Test Item', 'price' => 99.99]);

        // Valid identifiers should work
        $items = $this->repository->findBy(['name' => 'Test Item']);
        $this->assertCount(1, $items);

        $items = $this->repository->findBy(['price' => ['>=', 50]]);
        $this->assertCount(1, $items);
    }

    public function testUnsafeIdentifierInOrderByThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        $this->repository->findBy([], ['invalid; DROP TABLE' => 'ASC']);
    }

    public function testUnsafeIdentifierInAggregateThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        $this->repository->sum('invalid; DROP TABLE');
    }
}

class SimpleItem
{
    public function __construct(
        public int $id,
        public string $name,
        public float $price
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['name'],
            (float) $data['price']
        );
    }
}

class SimpleItemRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, SimpleItem::class, 'items');
    }
}
