<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

class CustomIdTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private CustomIdRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE products (
                id VARCHAR(50) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10, 2) NOT NULL
            )
        ');

        $this->repository = new CustomIdRepository($this->connection);
    }

    public function testCreateWithCustomId(): void
    {
        $product = $this->repository->create([
            'id' => 'PROD-001',
            'name' => 'Test Product',
            'price' => 99.99,
        ]);

        $this->assertInstanceOf(CustomIdProduct::class, $product);
        $this->assertEquals('PROD-001', $product->id);
        $this->assertEquals('Test Product', $product->name);
    }

    public function testCreateWithUuid(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

        $product = $this->repository->create([
            'id' => $uuid,
            'name' => 'UUID Product',
            'price' => 199.99,
        ]);

        $this->assertEquals($uuid, $product->id);

        $found = $this->repository->find($uuid);
        $this->assertNotNull($found);
        $this->assertEquals($uuid, $found->id);
    }

    public function testFindByWithInListCustomId(): void
    {
        $this->repository->create(['id' => 'PROD-X', 'name' => 'Product X', 'price' => 10.00]);
        $this->repository->create(['id' => 'PROD-Y', 'name' => 'Product Y', 'price' => 20.00]);
        $this->repository->create(['id' => 'PROD-Z', 'name' => 'Product Z', 'price' => 30.00]);

        // Use associative array syntax for explicit IN operator
        $products = $this->repository->findBy(['id' => ['IN' => ['PROD-X', 'PROD-Z']]]);

        $this->assertCount(2, $products);
    }
}

class CustomIdProduct
{
    public function __construct(
        public string $id,
        public string $name,
        public float $price
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'],
            (float) $data['price']
        );
    }
}

class CustomIdRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, CustomIdProduct::class, 'products');
    }
}
