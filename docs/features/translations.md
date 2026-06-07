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

Set locale for the next query. Adds LEFT JOIN with translation table. Pass an
optional second argument to enable a [fallback locale](#fallback-locale).

```php
public function withLocale(string $locale, ?string $fallbackLocale = null): static
```

```php
$product = $repo->withLocale('en')->find(1);
$products = $repo->withLocale('uk')->findBy(['status' => 'active']);
$products = $repo->withLocale('de')->findAll();

// With fallback: fields empty/missing in 'ru' are taken from 'uk'
$products = $repo->withLocale('ru', 'uk')->findBy([]);
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
3. **Reset** the locale after the query executes — so it never leaks into the next query

```sql
SELECT p.*, tr.name, tr.description
FROM products p
LEFT JOIN product_translations tr
    ON tr.product_id = p.id AND tr.locale = 'uk'
```

LEFT JOIN ensures that records without translations are still returned (translated fields will be `null`).

---

## Fallback Locale

Pass a second locale to `withLocale()` to fill values that are **missing or empty**
in the active locale from a fallback — useful for showing the default-language value
until a translation is provided.

```php
// Show Russian; for any field with no Russian value yet, use Ukrainian.
$products = $repo->withLocale('ru', 'uk')->findBy(['status' => 'active']);
```

A second LEFT JOIN on the fallback locale is added and every translated field is
`COALESCE`d over it:

```sql
SELECT p.*,
       COALESCE(NULLIF(tr.name, ''), tr_fb.name)              AS name,
       COALESCE(NULLIF(tr.description, ''), tr_fb.description) AS description
FROM products p
LEFT JOIN product_translations tr
    ON tr.product_id = p.id AND tr.locale = 'ru'
LEFT JOIN product_translations tr_fb
    ON tr_fb.product_id = p.id AND tr_fb.locale = 'uk'
```

::: warning Empty strings count as "missing"
`NULLIF(tr.field, '')` means an **empty string** in the active locale is treated as
"no translation" and replaced by the fallback value. If you need to keep intentional
empty values, don't store them as empty strings (use `NULL`), or omit the fallback.
:::

- The fallback applies per field: a field that **does** have a value in the active
  locale is never overridden.
- When `$fallbackLocale` is `null`, equal to `$locale`, or the repository has no
  `$translationConfig`, no second JOIN is added — behaviour is identical to a plain
  `withLocale($locale)`.
- The fallback **propagates to eager-loaded relations** together with the active
  locale (see below).
- Fallback (and the empty-string check) targets **text fields**. Don't list
  numeric/date columns in `fields` when using a fallback — `NULLIF(col, '')` makes
  MySQL silently replace a legitimate `0`, and PostgreSQL rejects the query with a
  type error.

---

## Writing Translations

Everything above is read-side (the `LEFT JOIN`). To **create** translation rows,
use `seedTranslations()` — a helper for the common "fill every active locale on
create" case.

### seedTranslations()

Insert one translation row per locale from a single set of `$values`, skipping
any locale that already has a row.

```php
public function seedTranslations(int|string $id, array $locales, array $values): int
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$id` | `int\|string` | Foreign-key value linking the rows to the record (matches `foreignKey`) |
| `$locales` | `list<string>` | Locales to seed |
| `$values` | `array<string, scalar\|null>` | Translated field => value pairs |

**Returns:** Number of rows actually inserted (locales that already had a row are skipped).

**Example:** seed all active locales right after creating the entity, so reads in
any locale return a value immediately (the operator translates them later):

```php
$product = $repo->create(['sku' => 'SKU-001', 'price' => 29.99]);

// Every locale starts from the operator's initial text.
$repo->seedTranslations($product->id, ['uk', 'en', 'de'], [
    'name'        => 'Product 1',
    'description' => 'Initial description',
]);
```

Only keys that appear in `$translationConfig['fields']` are written — extra keys
in `$values` are ignored, so you can pass a whole form payload:

```php
// 'sku' and 'price' are not translated fields → silently skipped.
$repo->seedTranslations($id, ['uk'], [
    'name'  => 'Назва',
    'sku'   => 'SKU-001',   // ignored
    'price' => 29.99,       // ignored
]);
```

::: info Idempotent — existing rows are preserved
Skipping relies on a `UNIQUE(foreignKey, locale)` constraint on the translation
table (see the [schema above](#setup)). Re-running `seedTranslations()` for the
same record never overwrites an existing locale — it only fills in the missing
ones. Use it to back-fill locales added after the entity was created:

```php
// Later a new locale goes live — back-fill only it, existing rows untouched.
$repo->seedTranslations($id, ['uk', 'en', 'de', 'pl'], $values); // inserts 'pl' only
```
:::

::: tip Single-locale edits
`seedTranslations()` is for the create/back-fill case. To update one locale's
text, write the translation row directly — e.g. a dedicated translation
repository with `updateBy(['product_id' => $id, 'locale' => 'uk'], $values)`.
:::

::: warning Cross-platform note
The skip is emitted per platform: `INSERT IGNORE` (MySQL/MariaDB),
`INSERT OR IGNORE` (SQLite), and `INSERT … ON CONFLICT DO NOTHING` (PostgreSQL
and others). All three require the `UNIQUE(foreignKey, locale)` constraint to
recognise a duplicate.
:::

When the repository has no `$translationConfig`, or `$locales` is empty, or none
of `$values` matches a translated field, the call is a no-op and returns `0`.

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

Translations work with eager loading. When `withLocale()` is used together with `with()`, the locale — and the fallback locale, if any — automatically propagates to all related repositories:

```php
$products = $repo
    ->with(['category', 'tags'])
    ->withLocale('ru', 'uk')
    ->findBy(['status' => 'active']);

// Both products AND their related categories/tags get translated fields,
// resolved with the same 'ru' → 'uk' fallback rule
// (if their repositories also have $translationConfig defined)
```

::: tip Locale Propagation
Each related repository must have its own `$translationConfig` to include translations. Repositories without it will simply ignore the propagated locale.
:::

### Filtering Through Relation EXISTS

When you filter a parent repository by a translated field on a related repository (`relation.translatedField`), the EXISTS subquery automatically `LEFT JOIN`s the related repo's translation table and resolves the field against it — provided the active locale is set on the parent.

```php
// User has many Articles, Article has translationConfig with 'title'
$users->withLocale('uk')->findBy(['articles.title' => 'Привіт']);
```

Generated SQL (simplified):

```sql
SELECT u.* FROM users u
WHERE EXISTS (
    SELECT 1
    FROM articles r
    LEFT JOIN article_translations r_t
        ON r_t.article_id = r.id AND r_t.locale = :locale
    WHERE r.user_id = u.id
      AND r_t.title = :value
)
```

Non-translated fields on the same relation still target the relation table directly (e.g. `articles.slug`). Mixing both in one criteria works:

```php
$users->withLocale('uk')->findBy([
    'articles.title' => 'Привіт',  // → translation alias
    'articles.slug'  => 'hello',    // → relation alias
]);
```

::: warning Fallback does not apply to filtering
A [fallback locale](#fallback-locale) affects only the **values returned** in the SELECT. Relation `EXISTS` filters match strictly against the requested locale: a row whose translated field exists *only* in the fallback locale will **not** match `relation.translatedField`, even though it would be shown via the fallback. This is intentional — a filter is a predicate over the actual data for the requested locale, not over the presentation value.
:::

See [Criteria → Relation Filters](/features/criteria#relation-filters) for relation criteria details.

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