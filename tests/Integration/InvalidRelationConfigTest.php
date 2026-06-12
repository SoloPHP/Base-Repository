<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\HasMany;

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

    public function testNonRelationConfigValueThrowsOnQuery(): void
    {
        $repository = new InvalidConfigRepository1($this->connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid relation config 'invalid': expected a Relation instance, got array");

        $repository->findBy(['name' => 'Test']);
    }

    public function testMissingRepositoryPropertyThrowsOnQuery(): void
    {
        $repository = new InvalidConfigRepository2($this->connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Relation 'invalid' points to missing repository property '\$nonExistentRepository'"
        );

        $repository->findBy(['name' => 'Test']);
    }

    public function testNonRelationConfigValueThrowsOnEagerLoad(): void
    {
        $repository = new InvalidConfigRepository1($this->connection);
        $this->connection->executeStatement("INSERT INTO items (name) VALUES ('Test')");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid relation config 'invalid': expected a Relation instance, got array");

        // findAll() compiles no criteria, so the throw comes from the eager loader.
        $repository->with(['invalid'])->findAll();
    }

    public function testUnknownRelationNameInWithIsIgnored(): void
    {
        $repository = new InvalidConfigRepository1($this->connection);
        $this->connection->executeStatement("INSERT INTO items (name) VALUES ('Test')");

        // Names absent from relationConfig are tolerated: the eager loader
        // skips them without touching the (broken) 'invalid' entry, and
        // criteria-free findAll() never compiles relation metadata.
        $items = $repository->with(['unknownName'])->findAll();

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

// Repository with invalid relation config (non-Relation value - array instead of RelationType)
class InvalidConfigRepository1 extends BaseRepository
{
    protected array $relationConfig = [
        'invalid' => ['hasMany', 'someRepo', 'item_id', 'setInvalid'], // Old array format
    ];

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, InvalidItem::class, 'items');
    }
}

// Repository with invalid relation config (missing repository property)
class InvalidConfigRepository2 extends BaseRepository
{
    protected array $relationConfig = [];

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        $this->relationConfig = [
            'invalid' => new HasMany(
                repository: 'nonExistentRepository',
                foreignKey: 'item_id',
                setter: 'setInvalid',
            ),
        ];
        parent::__construct($connection, InvalidItem::class, 'items');
    }
}
