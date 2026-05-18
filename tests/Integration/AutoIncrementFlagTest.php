<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

class AutoIncrementFlagTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE manual_pk_items (
                id VARCHAR(50) PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE auto_pk_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL
            )
        ');
    }

    public function testCreateWithoutPkThrowsWhenAutoIncrementDisabled(): void
    {
        $repo = new ManualPkRepository($this->connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('primary key "id" must be provided');

        $repo->create(['name' => 'Orphan']);
    }

    public function testCreateWithExplicitPkOnAutoIncrementTableUsesProvidedPk(): void
    {
        $repo = new AutoPkRepository($this->connection);

        $item = $repo->create(['id' => 4242, 'name' => 'Explicit']);

        $this->assertSame(4242, $item->id);
        $this->assertSame('Explicit', $item->name);
    }
}

class ManualPkItem
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self((string) $data['id'], (string) $data['name']);
    }
}

class ManualPkRepository extends BaseRepository
{
    protected bool $autoIncrement = false;

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, ManualPkItem::class, 'manual_pk_items');
    }
}

class AutoPkItem
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], (string) $data['name']);
    }
}

class AutoPkRepository extends BaseRepository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, AutoPkItem::class, 'auto_pk_items');
    }
}
