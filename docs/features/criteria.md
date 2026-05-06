# Criteria Syntax

The criteria array is used by `findBy()`, `findOneBy()`, `updateBy()`, `deleteBy()`, `count()`, `exists()`, and all aggregate methods.

## Basic Patterns

### Equality

```php
// WHERE status = 'active'
$repo->findBy(['status' => 'active']);

// WHERE user_id = 5
$repo->findBy(['user_id' => 5]);
```

### NULL Check

```php
// WHERE deleted_at IS NULL
$repo->findBy(['deleted_at' => null]);
```

### IN List

Pass a sequential array for IN:

```php
// WHERE id IN (1, 2, 3)
$repo->findBy(['id' => [1, 2, 3]]);

// WHERE status IN ('active', 'pending')
$repo->findBy(['status' => ['active', 'pending']]);
```

::: tip Empty list
An empty array (`['id' => []]`) compiles to `1 = 0` — the query returns no rows. This makes it safe to feed in pre-filtered ID lists without an extra `if (empty($ids))` guard.
:::

---

## Operator Syntax

Use an associative array with operator as key:

### Comparison Operators

```php
// WHERE age > 18
$repo->findBy(['age' => ['>' => 18]]);

// WHERE age >= 18
$repo->findBy(['age' => ['>=' => 18]]);

// WHERE age < 65
$repo->findBy(['age' => ['<' => 65]]);

// WHERE age <= 65
$repo->findBy(['age' => ['<=' => 65]]);
```

### Equality Operators

```php
// WHERE status = 'active'
$repo->findBy(['status' => ['=' => 'active']]);

// WHERE status != 'deleted'
$repo->findBy(['status' => ['!=' => 'deleted']]);

// WHERE status <> 'deleted'
$repo->findBy(['status' => ['<>' => 'deleted']]);
```

### NULL via Operators

```php
// WHERE verified_at IS NULL
$repo->findBy(['verified_at' => ['=' => null]]);

// WHERE verified_at IS NOT NULL
$repo->findBy(['verified_at' => ['!=' => null]]);
```

::: warning
Only `=`, `!=`, and `<>` accept `null`. Other operators (`<`, `LIKE`, `IN`, `BETWEEN`, …) throw `InvalidArgumentException` when given `null`.
:::

### LIKE Operator

```php
// WHERE name LIKE '%john%'
$repo->findBy(['name' => ['LIKE' => '%john%']]);

// WHERE email LIKE '%@gmail.com'
$repo->findBy(['email' => ['LIKE' => '%@gmail.com']]);

// WHERE name NOT LIKE '%test%'
$repo->findBy(['name' => ['NOT LIKE' => '%test%']]);
```

### IN / NOT IN Operators

```php
// WHERE status IN ('active', 'pending')
$repo->findBy(['status' => ['IN' => ['active', 'pending']]]);

// WHERE status NOT IN ('deleted', 'banned')
$repo->findBy(['status' => ['NOT IN' => ['deleted', 'banned']]]);
```

`IN` and `NOT IN` accept either an array or a single scalar (auto-wrapped). Empty arrays are short-circuited:

| Input                       | Result   |
|-----------------------------|----------|
| `['IN' => []]`              | `1 = 0`  |
| `['NOT IN' => []]`          | `1 = 1`  |

### BETWEEN Operator

```php
// WHERE price BETWEEN 100 AND 500
$repo->findBy(['price' => ['BETWEEN' => [100, 500]]]);

// WHERE age BETWEEN 18 AND 65
$repo->findBy(['age' => ['BETWEEN' => [18, 65]]]);

// Works with dates too
$repo->findBy(['created_at' => ['BETWEEN' => ['2024-01-01', '2024-12-31']]]);
```

::: tip
BETWEEN is inclusive on both ends. `['BETWEEN' => [10, 20]]` matches 10, 15, and 20. The value must be an array of exactly two elements; anything else throws `InvalidArgumentException`.
:::

---

## Complete Reference

| Pattern | Example | SQL |
|---------|---------|-----|
| Equality | `['status' => 'active']` | `status = ?` |
| NULL | `['deleted_at' => null]` | `deleted_at IS NULL` |
| IN (list) | `['id' => [1,2,3]]` | `id IN (?, ?, ?)` |
| Empty list | `['id' => []]` | `1 = 0` |
| `=` | `['status' => ['=' => 'active']]` | `status = ?` |
| `!=` | `['status' => ['!=' => 'draft']]` | `status != ?` |
| `<>` | `['status' => ['<>' => 'draft']]` | `status <> ?` |
| `<` | `['age' => ['<' => 18]]` | `age < ?` |
| `>` | `['age' => ['>' => 18]]` | `age > ?` |
| `<=` | `['age' => ['<=' => 65]]` | `age <= ?` |
| `>=` | `['age' => ['>=' => 18]]` | `age >= ?` |
| `LIKE` | `['name' => ['LIKE' => '%john%']]` | `name LIKE ?` |
| `NOT LIKE` | `['name' => ['NOT LIKE' => '%test%']]` | `name NOT LIKE ?` |
| `IN` | `['status' => ['IN' => ['a', 'b']]]` | `status IN (?, ?)` |
| `NOT IN` | `['status' => ['NOT IN' => ['x']]]` | `status NOT IN (?)` |
| `BETWEEN` | `['price' => ['BETWEEN' => [100, 500]]]` | `price BETWEEN ? AND ?` |
| IS NULL | `['deleted_at' => ['=' => null]]` | `deleted_at IS NULL` |
| IS NOT NULL | `['deleted_at' => ['!=' => null]]` | `deleted_at IS NOT NULL` |

---

## AND / OR Groups

Top-level criteria are AND-joined. Use the reserved keys `'OR'` and `'AND'` to nest groups with explicit boolean connectors. Both must contain an array — anything else throws `InvalidArgumentException`.

```php
// WHERE category = 'fruit' OR category = 'sweet'
$repo->findBy([
    'OR' => [
        ['category' => 'fruit'],
        ['category' => 'sweet'],
    ],
]);

// WHERE active = 1 AND (category = 'fruit' OR category = 'sweet')
$repo->findBy([
    'active' => 1,
    'OR' => [
        ['category' => 'fruit'],
        ['category' => 'sweet'],
    ],
]);
```

Groups recurse arbitrarily deep:

```php
// WHERE (status = 'A' OR (verified = 1 AND role IN ('admin','moderator')))
$repo->findBy([
    'OR' => [
        ['status' => 'A'],
        ['AND' => [
            ['verified' => 1],
            ['role' => ['admin', 'moderator']],
        ]],
    ],
]);
```

### List-form sub-criteria

Each numerically-keyed entry inside a group is itself an AND-block. This lets you OR together multi-condition branches without invented column names:

```php
// (category = 'fruit' AND active = 1) OR (price >= 35)
$repo->findBy([
    'OR' => [
        ['category' => 'fruit', 'active' => 1],   // first branch — AND-joined
        ['price' => ['>=' => 35]],                 // second branch
    ],
]);
```

---

## Combining Top-level Conditions

All top-level keys are combined with AND:

```php
// WHERE status = 'active' AND role = 'admin' AND age >= 18
$repo->findBy([
    'status' => 'active',
    'role' => 'admin',
    'age' => ['>=' => 18]
]);
```

---

## Relation Filters

Filter by related entities using dot-notation (`relation.field`). The relation must be defined in `$relationConfig`. Compiles to a correlated `EXISTS` subquery against the related table.

### EXISTS — has matching related record

```php
// Posts with at least one approved comment
$repo->findBy(['comments.status' => 'approved']);

// Posts by admin users
$repo->findBy(['user.role' => 'admin']);

// Posts with comments created after a date
$repo->findBy(['comments.created_at' => ['>=' => '2024-01-01']]);
```

Multiple `relation.*` keys for the **same** relation share a single EXISTS body (AND-joined inside):

```php
// EXISTS (... WHERE _r1.status = 'approved' AND _r1.rating >= 4)
$repo->findBy([
    'comments.status' => 'approved',
    'comments.rating' => ['>=' => 4],
]);
```

### NOT EXISTS — has no matching related record

Prefix the relation with `!`:

```php
// Posts with no spam comments
$repo->findBy(['!comments.status' => 'spam']);

// Posts that have no comments at all
$repo->findBy(['!comments.id' => ['>' => 0]]);
```

### Mixing EXISTS and NOT EXISTS

```php
// Admins with no spam comments
$repo->findBy([
    'user.role' => 'admin',          // EXISTS user
    '!comments.status' => 'spam',    // NOT EXISTS spam comment
]);
```

### Supported features in relation filters

All operators, NULL checks, and OR/AND groups work inside the EXISTS body:

```php
$repo->findBy([
    'comments.type' => ['review', 'question'],   // IN
    'comments.rating' => ['>=' => 4],            // operator
    'comments.deleted_at' => null,               // NULL
]);

// Group inside the EXISTS body — note "comments.OR"
$repo->findBy([
    'comments.OR' => [
        ['status' => 'approved'],
        ['status' => 'featured'],
    ],
]);
```

### Relation types

All four relation types defined in `$relationConfig` are supported in criteria:

| Relation | EXISTS shape |
|----------|-------------|
| `HasMany` / `HasOne` | `EXISTS (SELECT 1 FROM related r WHERE r.fk = base.pk AND ...)` |
| `BelongsTo`          | `EXISTS (SELECT 1 FROM related r WHERE r.pk = base.fk AND ...)` |
| `BelongsToMany`      | `EXISTS (SELECT 1 FROM pivot p INNER JOIN related r ON r.pk = p.related_fk WHERE p.parent_fk = base.pk AND ...)` |

::: warning Validation rules
- `!` prefix is only valid on relation dot-notation. `'!email' => 'x'` (no dot) throws.
- Unknown relation **with** `!` throws — `'!unknownRelation.field'` is treated as a typo, not silently ignored.
- Unknown relation **without** `!` falls through as a qualified column reference (`relation.field` is taken literally), useful for already-joined columns from a custom query.
:::

::: warning Self-relations in updateBy / forceDeleteBy
`updateBy()` and `forceDeleteBy()` reject EXISTS subqueries that target the same table being updated/deleted (MySQL forbids referencing the target table inside a subquery's `FROM`). The library throws `InvalidArgumentException` early with a clear message rather than letting the database fail.

For self-relations (e.g., a `parent_id` on `categories`), pre-fetch the matching IDs and use a plain `IN` filter:

```php
$ids = $repo->findBy(['children.name' => 'x']);          // OK in SELECT
$repo->updateBy(['id' => array_column($ids, 'id')], ...); // safe
```
:::

### Translations inside EXISTS

If the related repository declares a `$translationConfig` and `withLocale()` is active, the EXISTS body automatically `LEFT JOIN`s the translation table and resolves translated fields against it:

```php
class ArticleRepository extends BaseRepository {
    protected ?array $translationConfig = [
        'table' => 'article_translations',
        'foreignKey' => 'article_id',
        'fields' => ['title', 'body'],
    ];
}

// User has many Articles. Filter parents by a translated child field:
$users->withLocale('uk')->findBy(['articles.title' => 'Привіт']);
// Generates EXISTS (SELECT 1 FROM articles r LEFT JOIN article_translations r_t
//   ON r_t.article_id = r.id AND r_t.locale = :locale
//   WHERE r.user_id = u.id AND r_t.title = :value)
```

Non-translated fields keep targeting the relation table directly. See [Translations](/features/translations) for full setup.

---

## Soft Delete Special Value

When soft delete is enabled, use `'*'` to include deleted records:

```php
// Only active (default)
$users = $repo->findBy([]);

// Only deleted
$users = $repo->findBy(['deleted_at' => ['!=' => null]]);

// ALL records (active + deleted)
$users = $repo->findBy(['deleted_at' => '*']);
```

See [Soft Delete](/features/soft-delete) for more details.

---

## Security

All identifiers and operators are validated:

- Column names match `/^!?[A-Za-z_][A-Za-z0-9_.]*$/` — letters, digits, underscores, and dots only. The optional leading `!` is reserved for relation dot-notation.
- Only the operators listed in this page are accepted; everything else throws.
- All values are bound as named parameters — no string interpolation, no SQL injection.

::: warning User input
If accepting column names from user input (sort fields, filter keys, …), always validate against an allow-list:

```php
$allowedSortColumns = ['name', 'created_at', 'status'];
$sortColumn = in_array($input['sort'], $allowedSortColumns, true)
    ? $input['sort']
    : 'created_at';
```
:::
