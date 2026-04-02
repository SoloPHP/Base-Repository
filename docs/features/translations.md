# Translations

Automatic LEFT JOIN with a translation table when a locale is set. Translated fields are included in query results alongside the main record.

## Setup

Define `$translationConfig` in your repository:

```php
class ProductRepository extends BaseRepository
{
    protected ?array $translationConfig = [
        'table' => 'product_translations',
        'foreignKey' => 'product_id',
        'fields' => ['name', 'description', 'h1', 'meta_title', 'meta_description'],
    ];

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, Product::class, 'products');
    }
}
```

::: info Database Schema
Ensure your translation table exists:

```sql
CREATE TABLE product_translations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    locale VARCHAR(5) NOT NULL,
    name VARCHAR(255),
    description TEXT,
    h1 VARCHAR(255),
    meta_title VARCHAR(255),
    meta_description TEXT,
    UNIQUE(product_id, locale),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```
:::

### Config Reference

| Key | Type | Description |
|-----|------|-------------|
| `table` | `string` | Translation table name |
| `foreignKey` | `string` | Foreign key column in translation table pointing to the main table |
| `fields` | `list<string>` | Translated field names to select |

---

## Basic Usage

Use `withLocale()` to include translated fields in your query:

```php
// Fetch products with Ukrainian translations
$products = $repo->withLocale('uk')->findBy(['status' => 'active']);

// Each product now has translated fields (name, description, etc.)
echo $products[0]->name; // "Товар 1"
```

`withLocale()` is a **one-shot modifier** — the locale resets after the query executes:

```php
$repo->withLocale('uk')->findBy([]); // with translations
$repo->findBy([]);                    // without translations
```

This follows the same pattern as `with()` for eager loading.

---

## Methods

### withLocale()

Set locale for the next query. Adds LEFT JOIN with translation table.

```php
public function withLocale(string $locale): static
```

```php
$product = $repo->withLocale('en')->find(1);
$products = $repo->withLocale('uk')->findBy(['status' => 'active']);
$products = $repo->withLocale('de')->findAll();
```

### withoutLocale()

Clear a previously set locale before executing a query.

```php
public function withoutLocale(): static
```

```php
$repo->withLocale('uk');
// Changed my mind...
$repo->withoutLocale();
$products = $repo->findBy([]); // No translation JOIN
```

---

## How It Works

When `withLocale()` is called, the next query will:

1. **LEFT JOIN** the translation table on `foreignKey = primaryKey AND locale = :locale`
2. **Select** all configured translated fields from the translation table
3. **Reset** the locale after building the query

```sql
SELECT p.*, tr.name, tr.description
FROM products p
LEFT JOIN product_translations tr
    ON tr.product_id = p.id AND tr.locale = 'uk'
```

LEFT JOIN ensures that records without translations are still returned (translated fields will be `null`).

---

## Model Setup

Your model should accept translated fields as nullable:

```php
class Product
{
    public function __construct(
        public int $id,
        public string $sku,
        public float $price,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $h1 = null,
        public ?string $meta_title = null,
        public ?string $meta_description = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['sku'],
            (float) $data['price'],
            $data['name'] ?? null,
            $data['description'] ?? null,
            $data['h1'] ?? null,
            $data['meta_title'] ?? null,
            $data['meta_description'] ?? null,
        );
    }
}
```

---

## Practical Examples

### Multi-language Product Catalog

```php
class ProductRepository extends BaseRepository
{
    protected ?array $translationConfig = [
        'table' => 'product_translations',
        'foreignKey' => 'product_id',
        'fields' => ['name', 'description', 'h1', 'meta_title', 'meta_description'],
    ];

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, Product::class, 'products');
    }

    public function findActiveForLocale(string $locale): array
    {
        return $this->withLocale($locale)->findBy(
            ['status' => 'active'],
            ['sort_order' => 'ASC']
        );
    }

    public function findBySlugForLocale(string $slug, string $locale): ?Product
    {
        return $this->withLocale($locale)->findOneBy(['slug' => $slug]);
    }
}
```

### Combining with Eager Loading

Translations work with eager loading. When `withLocale()` is used together with `with()`, the locale automatically propagates to all related repositories:

```php
$products = $repo
    ->with(['category', 'tags'])
    ->withLocale('uk')
    ->findBy(['status' => 'active']);

// Both products AND their related categories/tags will have translated fields
// (if their repositories also have $translationConfig defined)
```

::: tip Locale Propagation
Each related repository must have its own `$translationConfig` to include translations. Repositories without it will simply ignore the propagated locale.
:::

### Combining with Soft Delete

Translations work with soft delete:

```php
class ArticleRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';
    protected ?array $translationConfig = [
        'table' => 'article_translations',
        'foreignKey' => 'article_id',
        'fields' => ['title', 'body'],
    ];
}

// Only active articles, with translations
$articles = $repo->withLocale('en')->findBy([]);
```

---

## Without Translation Config

If `$translationConfig` is not set, `withLocale()` and `withoutLocale()` are safe no-ops:

```php
class LogRepository extends BaseRepository
{
    // No $translationConfig
}

$repo->withLocale('uk')->findBy([]); // Works fine, no JOIN added
```