<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

/**
 * End-to-end coverage for OR/AND groups and list-form sub-criteria
 * — features that go through the recursive walker in CriteriaBuilder.
 */
class CriteriaGroupsTest extends TestCase
{
    private Connection $connection;
    private GroupItemRepository $repo;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) NOT NULL,
                category VARCHAR(50) NOT NULL,
                price INTEGER NOT NULL,
                active INTEGER NOT NULL DEFAULT 1
            )
        ');
        $this->repo = new GroupItemRepository($this->connection);

        $this->repo->insert(['name' => 'Apple',  'category' => 'fruit', 'price' => 10, 'active' => 1]);
        $this->repo->insert(['name' => 'Banana', 'category' => 'fruit', 'price' => 20, 'active' => 0]);
        $this->repo->insert(['name' => 'Carrot', 'category' => 'veg',   'price' => 30, 'active' => 1]);
        $this->repo->insert(['name' => 'Donut',  'category' => 'sweet', 'price' => 40, 'active' => 1]);
    }

    public function testOrGroupMatchesEither(): void
    {
        $items = $this->repo->findBy([
            'OR' => [
                ['category' => 'fruit'],
                ['category' => 'sweet'],
            ],
        ], ['id' => 'ASC']);

        $this->assertCount(3, $items);
        $this->assertSame(['Apple', 'Banana', 'Donut'], array_column($items, 'name'));
    }

    public function testNestedOrInsideAnd(): void
    {
        $items = $this->repo->findBy([
            'active' => 1,
            'OR' => [
                ['category' => 'fruit'],
                ['category' => 'sweet'],
            ],
        ], ['id' => 'ASC']);

        // active=1 AND (fruit OR sweet) → Apple, Donut (Banana is inactive)
        $this->assertSame(['Apple', 'Donut'], array_column($items, 'name'));
    }

    public function testListFormSubCriteriaInOr(): void
    {
        // Each list entry is its own AND-group; entries are OR'd together.
        $items = $this->repo->findBy([
            'OR' => [
                ['category' => 'fruit', 'active' => 1],   // Apple only
                ['price' => ['>=' => 35]],                 // Donut only
            ],
        ], ['id' => 'ASC']);

        $this->assertSame(['Apple', 'Donut'], array_column($items, 'name'));
    }

    public function testCountWithOrGroup(): void
    {
        $count = $this->repo->count([
            'OR' => [
                ['category' => 'fruit'],
                ['category' => 'veg'],
            ],
        ]);
        $this->assertSame(3, $count);
    }
}

class GroupItem
{
    public function __construct(
        public int $id,
        public string $name,
        public string $category,
        public int $price,
        public int $active,
    ) {
    }

    public static function fromArray(array $r): self
    {
        return new self(
            (int) $r['id'],
            $r['name'],
            $r['category'],
            (int) $r['price'],
            (int) $r['active'],
        );
    }
}

class GroupItemRepository extends BaseRepository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, GroupItem::class, 'items', 'i');
    }
}
