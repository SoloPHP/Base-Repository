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

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIdentifierProvider')]
    public function testUnsafeIdentifierThrowsException(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        $this->repository->findBy([$identifier => 'test']);
    }

    public static function unsafeIdentifierProvider(): array
    {
        return [
            'sql injection' => ['1=1; DROP TABLE items; --'],
            'space in name' => ['field name'],
            'quote in name' => ["field'name"],
        ];
    }

    public function testUnsafeOperatorThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe operator');

        $this->repository->findBy(['name' => ['DROP' => 'value']]);
    }

    public function testUpdateNonExistentRecordThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Updated record not found');

        $this->repository->update(999, ['name' => 'Updated']);
    }

    public function testNonExistentRecordHandling(): void
    {
        // Delete returns zero
        $this->assertEquals(0, $this->repository->delete(999));

        // Find returns null
        $this->assertNull($this->repository->find(999));

        // FindOneBy returns null for non-matching criteria
        $this->repository->create(['name' => 'Test', 'price' => 10.00]);
        $this->assertNull($this->repository->findOneBy(['name' => 'NonExistent']));
    }

    public function testValidIdentifiersWork(): void
    {
        $this->repository->create(['name' => 'Test Item', 'price' => 99.99]);

        // Valid identifiers should work
        $items = $this->repository->findBy(['name' => 'Test Item']);
        $this->assertCount(1, $items);

        $items = $this->repository->findBy(['price' => ['>=' => 50]]);
        $this->assertCount(1, $items);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIdentifierContextProvider')]
    public function testUnsafeIdentifierInContextThrowsException(string $context, callable $action): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        $action($this->repository);
    }

    public static function unsafeIdentifierContextProvider(): array
    {
        return [
            'orderBy' => ['orderBy', fn($repo) => $repo->findBy([], ['invalid; DROP TABLE' => 'ASC'])],
            'aggregate' => ['aggregate', fn($repo) => $repo->sum('invalid; DROP TABLE')],
        ];
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
