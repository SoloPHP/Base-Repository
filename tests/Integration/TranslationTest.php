<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\HasMany;

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

    public function testMapToModel(): void
    {
        $product = $this->repository->mapToModel([
            'id' => 99,
            'sku' => 'MAP-001',
            'price' => 12.50,
            'name' => 'Mapped',
            'description' => 'Desc',
        ]);

        $this->assertEquals(99, $product->id);
        $this->assertEquals('MAP-001', $product->sku);
        $this->assertEquals('Mapped', $product->name);
    }

    public function testWithLocalePropagatesToEagerLoadedRelations(): void
    {
        // Create category table + translations
        $this->connection->executeStatement('
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug VARCHAR(50) NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE category_translations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER NOT NULL,
                locale VARCHAR(5) NOT NULL,
                title VARCHAR(255),
                UNIQUE(category_id, locale)
            )
        ');
        // Add category_id FK to products
        $this->connection->executeStatement('
            CREATE TABLE products_with_cat (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku VARCHAR(50) NOT NULL,
                price DECIMAL(10, 2) NOT NULL,
                category_id INTEGER
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE product_with_cat_translations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                locale VARCHAR(5) NOT NULL,
                name VARCHAR(255),
                description TEXT,
                UNIQUE(product_id, locale)
            )
        ');

        // Seed
        $this->connection->insert('categories', ['slug' => 'electronics']);
        $this->connection->insert('category_translations', [
            'category_id' => 1, 'locale' => 'uk', 'title' => 'Електроніка',
        ]);

        $this->connection->insert('products_with_cat', ['sku' => 'P1', 'price' => 10, 'category_id' => 1]);
        $this->connection->insert('product_with_cat_translations', [
            'product_id' => 1, 'locale' => 'uk', 'name' => 'Товар', 'description' => 'Опис',
        ]);

        $categoryRepo = new TranslatableCategoryRepository($this->connection);
        $productRepo = new ProductWithCategoryRepository($this->connection, $categoryRepo);

        // withLocale + with() — locale should propagate to category relation
        $products = $productRepo->withLocale('uk')->with(['products'])->findBy([]);

        $this->assertCount(1, $products);
        $this->assertEquals('Електроніка', $products[0]->title);
        $this->assertCount(1, $products[0]->products);
        $this->assertEquals('Товар', $products[0]->products[0]->name);
    }

    public function testWithoutLocaleClearsEagerLoadingLocale(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE cats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug VARCHAR(50) NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE cat_translations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER NOT NULL,
                locale VARCHAR(5) NOT NULL,
                title VARCHAR(255),
                UNIQUE(category_id, locale)
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE prods (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku VARCHAR(50) NOT NULL,
                price DECIMAL(10, 2) NOT NULL,
                category_id INTEGER
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE prod_translations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                locale VARCHAR(5) NOT NULL,
                name VARCHAR(255),
                description TEXT,
                UNIQUE(product_id, locale)
            )
        ');

        $this->connection->insert('cats', ['slug' => 'food']);
        $this->connection->insert('cat_translations', [
            'category_id' => 1, 'locale' => 'uk', 'title' => 'Їжа',
        ]);
        $this->connection->insert('prods', ['sku' => 'F1', 'price' => 5, 'category_id' => 1]);
        $this->connection->insert('prod_translations', [
            'product_id' => 1, 'locale' => 'uk', 'name' => 'Хліб', 'description' => 'Смачний',
        ]);

        $prodRepo = new ProdWithTranslationRepository($this->connection);
        $catRepo = new CatWithProductsRepository($this->connection, $prodRepo);

        // Set locale then clear it
        $catRepo->withLocale('uk');
        $catRepo->withoutLocale();

        $categories = $catRepo->with(['products'])->findBy([]);
        $this->assertCount(1, $categories);
        // No translations should be applied
        $this->assertNull($categories[0]->title);
        $this->assertNull($categories[0]->products[0]->name);
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

class TranslatableCategory
{
    public array $products = [];

    public function __construct(
        public int $id,
        public string $slug,
        public ?string $title = null,
    ) {
    }

    public function setProducts(array $products): void
    {
        $this->products = $products;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['slug'],
            $data['title'] ?? null,
        );
    }
}

class TranslatableCategoryProduct
{
    public function __construct(
        public int $id,
        public string $sku,
        public float $price,
        public int $category_id,
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
            (int) $data['category_id'],
            $data['name'] ?? null,
            $data['description'] ?? null,
        );
    }
}

class TranslatableCategoryRepository extends BaseRepository
{
    protected ?array $translationConfig = [
        'table' => 'product_with_cat_translations',
        'foreignKey' => 'product_id',
        'fields' => ['name', 'description'],
    ];

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, TranslatableCategoryProduct::class, 'products_with_cat');
    }
}

class ProductWithCategoryRepository extends BaseRepository
{
    protected array $relationConfig = [];
    protected ?array $translationConfig = [
        'table' => 'category_translations',
        'foreignKey' => 'category_id',
        'fields' => ['title'],
    ];

    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        public TranslatableCategoryRepository $categoryRepository,
    ) {
        $this->relationConfig = [
            'products' => new HasMany(
                repository: 'categoryRepository',
                foreignKey: 'category_id',
                setter: 'setProducts',
            ),
        ];
        parent::__construct($connection, TranslatableCategory::class, 'categories');
    }
}

class CatModel
{
    public array $products = [];

    public function __construct(
        public int $id,
        public string $slug,
        public ?string $title = null,
    ) {
    }

    public function setProducts(array $products): void
    {
        $this->products = $products;
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], $data['slug'], $data['title'] ?? null);
    }
}

class ProdModel
{
    public function __construct(
        public int $id,
        public string $sku,
        public float $price,
        public int $category_id,
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
            (int) $data['category_id'],
            $data['name'] ?? null,
            $data['description'] ?? null,
        );
    }
}

class ProdWithTranslationRepository extends BaseRepository
{
    protected ?array $translationConfig = [
        'table' => 'prod_translations',
        'foreignKey' => 'product_id',
        'fields' => ['name', 'description'],
    ];

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, ProdModel::class, 'prods');
    }
}

class CatWithProductsRepository extends BaseRepository
{
    protected array $relationConfig = [];
    protected ?array $translationConfig = [
        'table' => 'cat_translations',
        'foreignKey' => 'category_id',
        'fields' => ['title'],
    ];

    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        public ProdWithTranslationRepository $productRepository,
    ) {
        $this->relationConfig = [
            'products' => new HasMany(
                repository: 'productRepository',
                foreignKey: 'category_id',
                setter: 'setProducts',
            ),
        ];
        parent::__construct($connection, CatModel::class, 'cats');
    }
}