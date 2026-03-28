<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

class TranslationTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private TranslatableProductRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku VARCHAR(50) NOT NULL,
                price DECIMAL(10, 2) NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE product_translations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                locale VARCHAR(5) NOT NULL,
                name VARCHAR(255),
                description TEXT,
                UNIQUE(product_id, locale)
            )
        ');

        $this->repository = new TranslatableProductRepository($this->connection);

        // Seed products
        $this->connection->insert('products', ['sku' => 'SKU-001', 'price' => 29.99]);
        $this->connection->insert('products', ['sku' => 'SKU-002', 'price' => 49.99]);

        // Seed translations
        $this->connection->insert('product_translations', [
            'product_id' => 1, 'locale' => 'uk', 'name' => 'Товар 1', 'description' => 'Опис товару 1',
        ]);
        $this->connection->insert('product_translations', [
            'product_id' => 1, 'locale' => 'en', 'name' => 'Product 1', 'description' => 'Product 1 description',
        ]);
        $this->connection->insert('product_translations', [
            'product_id' => 2, 'locale' => 'uk', 'name' => 'Товар 2', 'description' => 'Опис товару 2',
        ]);
    }

    public function testFindByWithLocaleJoinsTranslations(): void
    {
        $products = $this->repository->withLocale('uk')->findBy([]);

        $this->assertCount(2, $products);
        $this->assertEquals('Товар 1', $products[0]->name);
        $this->assertEquals('Опис товару 1', $products[0]->description);
        $this->assertEquals('Товар 2', $products[1]->name);
    }

    public function testFindByWithDifferentLocale(): void
    {
        $products = $this->repository->withLocale('en')->findBy([]);

        $this->assertCount(2, $products);
        $this->assertEquals('Product 1', $products[0]->name);
        // Product 2 has no English translation
        $this->assertNull($products[1]->name);
    }

    public function testFindByWithoutLocaleReturnsNullFields(): void
    {
        $products = $this->repository->findBy([]);

        $this->assertCount(2, $products);
        $this->assertNull($products[0]->name);
        $this->assertNull($products[0]->description);
    }

    public function testFindWithLocale(): void
    {
        $product = $this->repository->withLocale('uk')->find(1);

        $this->assertNotNull($product);
        $this->assertEquals('Товар 1', $product->name);
        $this->assertEquals('SKU-001', $product->sku);
    }

    public function testFindOneByWithLocale(): void
    {
        $product = $this->repository->withLocale('uk')->findOneBy(['sku' => 'SKU-002']);

        $this->assertNotNull($product);
        $this->assertEquals('Товар 2', $product->name);
    }

    public function testFindAllWithLocale(): void
    {
        $products = $this->repository->withLocale('uk')->findAll();

        $this->assertCount(2, $products);
        $this->assertEquals('Товар 1', $products[0]->name);
        $this->assertEquals('Товар 2', $products[1]->name);
    }

    public function testLocaleResetsAfterQuery(): void
    {
        // First query with locale
        $products = $this->repository->withLocale('uk')->findBy([]);
        $this->assertEquals('Товар 1', $products[0]->name);

        // Second query without locale — should NOT have translations
        $products = $this->repository->findBy([]);
        $this->assertNull($products[0]->name);
    }

    public function testWithoutLocaleClearsLocale(): void
    {
        $this->repository->withLocale('uk');
        $this->repository->withoutLocale();

        $products = $this->repository->findBy([]);
        $this->assertNull($products[0]->name);
    }

    public function testWithLocaleWithCriteria(): void
    {
        $products = $this->repository->withLocale('uk')->findBy(['price' => ['>' => 30]]);

        $this->assertCount(1, $products);
        $this->assertEquals('Товар 2', $products[0]->name);
        $this->assertEquals(49.99, (float) $products[0]->price);
    }

    public function testWithLocaleWithOrderBy(): void
    {
        $products = $this->repository->withLocale('uk')->findBy([], ['price' => 'DESC']);

        $this->assertCount(2, $products);
        $this->assertEquals('Товар 2', $products[0]->name);
        $this->assertEquals('Товар 1', $products[1]->name);
    }

    public function testWithLocaleWithPagination(): void
    {
        $products = $this->repository->withLocale('uk')->findBy([], ['id' => 'ASC'], 1, 1);

        $this->assertCount(1, $products);
        $this->assertEquals('Товар 1', $products[0]->name);

        $products = $this->repository->withLocale('uk')->findBy([], ['id' => 'ASC'], 1, 2);

        $this->assertCount(1, $products);
        $this->assertEquals('Товар 2', $products[0]->name);
    }

    public function testWithLocaleOnRepositoryWithoutConfig(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE simple_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL
            )
        ');

        $repo = new NonTranslatableRepository($this->connection);
        $repo->create(['title' => 'Item 1']);

        // withLocale should be a no-op
        $items = $repo->withLocale('uk')->findBy([]);
        $this->assertCount(1, $items);

        // withoutLocale should also be a no-op
        $repo->withoutLocale();
        $items = $repo->findBy([]);
        $this->assertCount(1, $items);
    }

    public function testCountWithLocale(): void
    {
        // count uses table() internally, locale should not break it
        $count = $this->repository->withLocale('uk')->count([]);
        $this->assertEquals(2, $count);
    }

    public function testExistsWithLocale(): void
    {
        $exists = $this->repository->withLocale('uk')->exists(['sku' => 'SKU-001']);
        $this->assertTrue($exists);
    }
}

class TranslatableProduct
{
    public function __construct(
        public int $id,
        public string $sku,
        public float $price,
        public ?string $name = null,
        public ?string $description = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['sku'],
            (float) $data['price'],
            $data['name'] ?? null,
            $data['description'] ?? null,
        );
    }
}

class TranslatableProductRepository extends BaseRepository
{
    protected ?array $translationConfig = [
        'table' => 'product_translations',
        'foreignKey' => 'product_id',
        'fields' => ['name', 'description'],
    ];

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, TranslatableProduct::class, 'products');
    }
}

class NonTranslatableItem
{
    public function __construct(
        public int $id,
        public string $title,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], $data['title']);
    }
}

class NonTranslatableRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, NonTranslatableItem::class, 'simple_items');
    }
}