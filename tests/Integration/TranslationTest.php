<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\BelongsTo;
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

    public function testFallbackLocaleFillsMissingTranslation(): void
    {
        // Product 1 has 'en'; product 2 has only 'uk' → it falls back.
        $products = $this->repository->withLocale('en', 'uk')->findBy([], ['id' => 'ASC']);

        $this->assertCount(2, $products);
        $this->assertEquals('Product 1', $products[0]->name); // primary (en) present
        $this->assertEquals('Product 1 description', $products[0]->description);
        $this->assertEquals('Товар 2', $products[1]->name);   // missing en → uk fallback
        $this->assertEquals('Опис товару 2', $products[1]->description); // fell back to uk
    }

    public function testFallbackLocaleDoesNotOverridePresentTranslation(): void
    {
        // uk is present for product 1 — the en fallback must not replace it.
        $products = $this->repository->withLocale('uk', 'en')->findBy(['sku' => 'SKU-001']);

        $this->assertCount(1, $products);
        $this->assertEquals('Товар 1', $products[0]->name);
    }

    public function testFallbackLocaleFillsEmptyStringField(): void
    {
        // A 'de' row with an empty name but a present description.
        $this->connection->insert('product_translations', [
            'product_id' => 1, 'locale' => 'de', 'name' => '', 'description' => 'DE Beschreibung',
        ]);

        $products = $this->repository->withLocale('de', 'uk')->findBy(['sku' => 'SKU-001']);

        $this->assertCount(1, $products);
        $this->assertEquals('Товар 1', $products[0]->name);                // empty de.name → uk fallback
        $this->assertEquals('DE Beschreibung', $products[0]->description); // present de.description kept
    }

    public function testFallbackLocaleEqualToLocaleBehavesLikePlainLocale(): void
    {
        $products = $this->repository->withLocale('uk', 'uk')->findBy(['sku' => 'SKU-002']);

        $this->assertCount(1, $products);
        $this->assertEquals('Товар 2', $products[0]->name);
    }

    public function testWithLocaleDoesNotOverwriteBaseRowId(): void
    {
        // Product 1's 'en' translation row has PK 2, distinct from the product id 1.
        $product = $this->repository->withLocale('en')->find(1);

        $this->assertNotNull($product);
        $this->assertSame(1, $product->id); // base row id, not the translation row id
        $this->assertEquals('Product 1', $product->name);
    }

    public function testFallbackLocaleDoesNotOverwriteBaseRowId(): void
    {
        // Product 2 has no 'de' row; the uk fallback row has PK 3, distinct from id 2.
        $product = $this->repository->withLocale('de', 'uk')->find(2);

        $this->assertNotNull($product);
        $this->assertSame(2, $product->id); // neither tr nor tr_fb id leaks into id
        $this->assertEquals('Товар 2', $product->name);
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

    public function testWithLocalePropagatesToEagerLoadedRelations(): void
    {
        $this->createCategoryProductSchema();

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

    public function testFallbackLocalePropagatesToEagerLoadedRelations(): void
    {
        $this->createCategoryProductSchema();

        // Category has only 'uk'. Two products: P1 has 'en', P2 has only 'uk'.
        $this->connection->insert('categories', ['slug' => 'electronics']);
        $this->connection->insert('category_translations', [
            'category_id' => 1, 'locale' => 'uk', 'title' => 'Електроніка',
        ]);
        $this->connection->insert('products_with_cat', ['sku' => 'P1', 'price' => 10, 'category_id' => 1]);
        $this->connection->insert('products_with_cat', ['sku' => 'P2', 'price' => 20, 'category_id' => 1]);
        $this->connection->insert('product_with_cat_translations', [
            'product_id' => 1, 'locale' => 'uk', 'name' => 'Товар UK', 'description' => 'Опис',
        ]);
        $this->connection->insert('product_with_cat_translations', [
            'product_id' => 1, 'locale' => 'en', 'name' => 'Goods EN', 'description' => 'Desc EN',
        ]);
        $this->connection->insert('product_with_cat_translations', [
            'product_id' => 2, 'locale' => 'uk', 'name' => 'Товар2 UK', 'description' => 'Опис2',
        ]);

        $categoryRepo = new TranslatableCategoryRepository($this->connection);
        $productRepo = new ProductWithCategoryRepository($this->connection, $categoryRepo);

        // Request 'en' with 'uk' fallback.
        $products = $productRepo->withLocale('en', 'uk')->with(['products'])->findBy([]);

        $this->assertCount(1, $products);
        // Category has no 'en' → falls back to uk.
        $this->assertEquals('Електроніка', $products[0]->title);
        $this->assertCount(2, $products[0]->products);
        // P1 has 'en' → primary wins (NOT the uk fallback 'Товар UK').
        $this->assertEquals('Goods EN', $products[0]->products[0]->name);
        // P2 has no 'en' → falls back to uk.
        $this->assertEquals('Товар2 UK', $products[0]->products[1]->name);
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

    public function testEagerLoadDoesNotLeakLocaleWhenRelationSkipped(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE leak_cats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug VARCHAR(50) NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE leak_cat_tr (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER NOT NULL,
                locale VARCHAR(5) NOT NULL,
                title VARCHAR(255),
                UNIQUE(category_id, locale)
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE leak_prods (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku VARCHAR(50) NOT NULL,
                category_id INTEGER
            )
        ');

        $this->connection->insert('leak_cats', ['slug' => 'c1']);
        $this->connection->insert('leak_cat_tr', ['category_id' => 1, 'locale' => 'uk', 'title' => 'Кат']);
        // Product has NULL category_id → the BelongsTo findBy is skipped entirely.
        $this->connection->insert('leak_prods', ['sku' => 'P1', 'category_id' => null]);

        $catRepo = new LeakCategoryRepository($this->connection);
        $prodRepo = new LeakProductRepository($this->connection, $catRepo);

        $prods = $prodRepo->withLocale('uk')->with(['category'])->findBy([]);
        $this->assertCount(1, $prods);

        // The one-shot locale must NOT have leaked onto the shared category repo.
        $cats = $catRepo->findBy([]);
        $this->assertCount(1, $cats);
        $this->assertNull($cats[0]->title); // no translation JOIN => title stays null
    }

    public function testSeedTranslationsInsertsMissingLocales(): void
    {
        // Product 2 already has 'uk' (seeded in setUp) → it is skipped; en + de are new.
        $inserted = $this->repository->seedTranslations(
            2,
            ['uk', 'en', 'de'],
            ['name' => 'Seed name', 'description' => 'Seed desc'],
        );

        $this->assertSame(2, $inserted);

        $en = $this->connection->fetchAssociative(
            "SELECT name, description FROM product_translations WHERE product_id = 2 AND locale = 'en'"
        );
        $this->assertEquals('Seed name', $en['name']);
        $this->assertEquals('Seed desc', $en['description']);

        // The pre-existing 'uk' row must stay untouched.
        $uk = $this->connection->fetchAssociative(
            "SELECT name FROM product_translations WHERE product_id = 2 AND locale = 'uk'"
        );
        $this->assertEquals('Товар 2', $uk['name']);
    }

    public function testSeedTranslationsIsIdempotent(): void
    {
        // Product 1 already has both 'uk' and 'en' → nothing inserted, originals kept.
        $inserted = $this->repository->seedTranslations(1, ['uk', 'en'], ['name' => 'Overwrite?']);

        $this->assertSame(0, $inserted);

        $uk = $this->connection->fetchOne(
            "SELECT name FROM product_translations WHERE product_id = 1 AND locale = 'uk'"
        );
        $this->assertEquals('Товар 1', $uk);
    }

    public function testSeedTranslationsOnlyWritesConfiguredFields(): void
    {
        // 'sku' is not in translationConfig['fields'] and must be ignored (no such column).
        $inserted = $this->repository->seedTranslations(2, ['fr'], ['name' => 'Nom', 'sku' => 'X']);

        $this->assertSame(1, $inserted);

        $row = $this->connection->fetchAssociative(
            "SELECT name, description FROM product_translations WHERE product_id = 2 AND locale = 'fr'"
        );
        $this->assertEquals('Nom', $row['name']);
        $this->assertNull($row['description']); // not provided → left NULL
    }

    public function testSeedTranslationsNoOpWhenLocalesEmpty(): void
    {
        $this->assertSame(0, $this->repository->seedTranslations(2, [], ['name' => 'x']));
    }

    public function testSeedTranslationsNoOpWhenNoConfiguredFieldsProvided(): void
    {
        // None of the keys map to translationConfig['fields'] → no statement issued.
        $inserted = $this->repository->seedTranslations(2, ['fr'], ['unknown' => 'x']);

        $this->assertSame(0, $inserted);
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM product_translations WHERE product_id = 2 AND locale = 'fr'"
        );
        $this->assertEquals(0, $count);
    }

    public function testSeedTranslationsNoOpWithoutTranslationConfig(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE simple_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL
            )
        ');

        $repo = new NonTranslatableRepository($this->connection);

        $this->assertSame(0, $repo->seedTranslations(1, ['uk', 'en'], ['title' => 'x']));
    }

    private function createCategoryProductSchema(): void
    {
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

class LeakCategory
{
    public function __construct(
        public int $id,
        public string $slug,
        public ?string $title = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], $data['slug'], $data['title'] ?? null);
    }
}

class LeakCategoryRepository extends BaseRepository
{
    protected ?array $translationConfig = [
        'table' => 'leak_cat_tr',
        'foreignKey' => 'category_id',
        'fields' => ['title'],
    ];

    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, LeakCategory::class, 'leak_cats');
    }
}

class LeakProduct
{
    public ?LeakCategory $category = null;

    public function __construct(
        public int $id,
        public string $sku,
        public ?int $category_id = null,
    ) {
    }

    public function setCategory(?LeakCategory $category): void
    {
        $this->category = $category;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['sku'],
            isset($data['category_id']) ? (int) $data['category_id'] : null,
        );
    }
}

class LeakProductRepository extends BaseRepository
{
    protected array $relationConfig = [];

    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        public LeakCategoryRepository $categoryRepository,
    ) {
        $this->relationConfig = [
            'category' => new BelongsTo(
                repository: 'categoryRepository',
                foreignKey: 'category_id',
                setter: 'setCategory',
            ),
        ];
        parent::__construct($connection, LeakProduct::class, 'leak_prods');
    }
}