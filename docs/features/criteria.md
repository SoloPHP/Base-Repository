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

---

## Complete Reference

| Pattern | Example | SQL |
|---------|---------|-----|
| Equality | `['status' => 'active']` | `status = ?` |
| NULL | `['deleted_at' => null]` | `deleted_at IS NULL` |
| IN (list) | `['id' => [1,2,3]]` | `id IN (?, ?, ?)` |
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
| IS NULL | `['deleted_at' => ['=' => null]]` | `deleted_at IS NULL` |
| IS NOT NULL | `['deleted_at' => ['!=' => null]]` | `deleted_at IS NOT NULL` |

---

## Combining Conditions

All conditions are combined with AND:

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

Filter by related entities using dot-notation. Generates `EXISTS` subqueries.

### EXISTS (has matching related record)

```php
// Posts that have at least one approved comment
$repo->findBy(['comments.status' => 'approved']);

// Posts by admin users
$repo->findBy(['user.role' => 'admin']);

// Posts with comments created after date
$repo->findBy(['comments.created_at' => ['>=' => '2024-01-01']]);
```

### NOT EXISTS (has no matching related record)

Use `!` prefix:

```php
// Posts with NO spam comments
$repo->findBy(['!comments.status' => 'spam']);

// Posts with NO comments at all
$repo->findBy(['!comments.id' => ['>' => 0]]);
```

### Combined EXISTS / NOT EXISTS

```php
// Posts by admins with no spam comments
$repo->findBy([
    'user.role' => 'admin',           // EXISTS
    '!comments.status' => 'spam'      // NOT EXISTS
]);
```

### Supported Operators in Relations

```php
// IN list
$repo->findBy(['comments.type' => ['review', 'question']]);

// Comparison
$repo->findBy(['comments.rating' => ['>=' => 4]]);

// NULL check
$repo->findBy(['comments.deleted_at' => null]);
```

::: info Requirements
Relation filters require `$relationConfig` to be defined with the referenced relation.
:::

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

- Column names must match `/^[A-Za-z_][A-Za-z0-9_]*$/`
- Only allowed operators are accepted
- All values are bound as parameters (no SQL injection)

::: warning User Input
If accepting column names from user input, always validate against a whitelist:

```php
$allowedSortColumns = ['name', 'created_at', 'status'];
$sortColumn = in_array($input['sort'], $allowedSortColumns) 
    ? $input['sort'] 
    : 'created_at';
```
:::
