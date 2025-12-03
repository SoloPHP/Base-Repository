<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

class InvalidRelationConfigTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL
            )
        ');
    }

    public function testInvalidRelationConfigWithTooFewElements(): void
    {
        $repository = new InvalidConfigRepository1($this->connection);
        $item = $repository->create(['name' => 'Test']);

        // Should not throw, just skip invalid config
        $items = $repository->findBy(['name' => 'Test']);
        $this->assertCount(1, $items);
    }

    public function testInvalidRelationConfigWithMissingProperty(): void
    {
        $repository = new InvalidConfigRepository2($this->connection);
        $item = $repository->create(['name' => 'Test']);

        // Should not throw, just skip invalid config
        $items = $repository->findBy(['name' => 'Test']);
        $this->assertCount(1, $items);
    }

    public function testRelationCriteriaWithInvalidConfig(): void
    {
        $repository = new InvalidConfigRepository1($this->connection);
        $repository->create(['name' => 'Test']);

        // Using dot notation - invalid config should be skipped
        // But the relation criteria is just ignored, so it returns all items
        $items = $repository->findBy(['invalid.field' => 'value']);
        $this->assertCount(1, $items);
    }

    public function testRelationCriteriaWithMissingRepositoryProperty(): void
    {
        $repository = new InvalidConfigRepository2($this->connection);
        $repository->create(['name' => 'Test']);

        // Using dot notation - invalid config should be skipped
        $items = $repository->findBy(['invalid.field' => 'value']);
        $this->assertCount(1, $items);
    }
}

class InvalidItem
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

// Repository with invalid relation config (too few elements)
class InvalidConfigRepository1 extends BaseRepository
{
    protected array $relationConfig = [
        'invalid' => ['hasMany'], // Missing required elements
    ];

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, InvalidItem::class, 'items');
    }
}

// Repository with invalid relation config (missing property)
class InvalidConfigRepository2 extends BaseRepository
{
    protected array $relationConfig = [
        'invalid' => ['hasMany', 'nonExistentRepository', 'item_id', 'setInvalid'],
    ];

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, InvalidItem::class, 'items');
    }
}
