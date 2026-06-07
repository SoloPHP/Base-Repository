# Mutation Methods

## create()

Create a new record and return the hydrated model.

```php
public function create(array $data): TModel
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$data` | `array<string, mixed>` | Column => value pairs to insert |

**Returns:** Created model instance with ID populated.

**Throws:**
- `InvalidArgumentException` — when `$autoIncrement = false` on the repository and the PK is missing from `$data`.
- `RuntimeException` — when the row cannot be found after insert (driver returned no `lastInsertId`, or table is not AUTO_INCREMENT and you forgot to set `$autoIncrement = false`).

**Example:**

```php
// Auto-increment ID
$user = $repo->create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'status' => 'active'
]);
echo $user->id; // Auto-generated ID (e.g., 42)

// Custom ID (UUID, prefixed, etc.)
$product = $repo->create([
    'id' => 'PROD-' . uniqid(),
    'name' => 'Widget',
    'price' => 99.99
]);
echo $product->id; // 'PROD-6789abc123'
```

::: tip Custom IDs
For tables without AUTO_INCREMENT/SERIAL, set `protected bool $autoIncrement = false;` on the repository. The PK then becomes mandatory in `$data` and `create()` skips `lastInsertId()`. See [Custom IDs](/advanced/custom-ids).
:::

---

## insert()

Insert a record without returning the model. Useful for tables without auto-increment, pivot tables, or when you don't need the hydrated model back.

```php
public function insert(array $data): int
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$data` | `array<string, mixed>` | Column => value pairs to insert |

**Returns:** Number of affected rows.

**Example:**

```php
// Insert without fetching the model back
$repo->insert([
    'id' => Uuid::v4(),
    'name' => 'Widget',
    'price' => 99.99,
]);

// Log table — fire and forget
$logRepo->insert([
    'action' => 'user.login',
    'user_id' => $userId,
    'created_at' => date('Y-m-d H:i:s'),
]);
```

::: tip When to use insert() vs create()
Use `create()` when you need the hydrated model back. Use `insert()` when you just need to write data — it skips `lastInsertId()` and the extra `SELECT`.
:::

---

## insertMany()

Bulk insert multiple records.

```php
public function insertMany(list<array<string, mixed>> $records): int
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$records` | `list<array>` | Array of records to insert |

**Returns:** Number of affected rows.

**Example:**

```php
$affected = $repo->insertMany([
    ['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active'],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active'],
    ['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'pending'],
]);

echo "Inserted {$affected} records"; // "Inserted 3 records"
```

::: info Implementation Details
- Each chunk of up to 500 records is sent as a single multi-row `INSERT … VALUES (…),(…),…` statement
- Records within a chunk are grouped by their column-set, so heterogeneous records still produce one INSERT per group rather than per row
- Returns total affected rows count
:::

::: warning No implicit transaction
`insertMany()` does **not** wrap chunks in a transaction (consistent with `insert()` / `update()`). If one chunk fails mid-way, previously-inserted chunks remain committed. Wrap in `withTransaction()` for all-or-nothing semantics:

```php
$repo->withTransaction(fn($r) => $r->insertMany($records));
```
:::

---

## update()

Update a record by its primary key and return the updated model.

```php
public function update(int|string $id, array $data): TModel
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$id` | `int\|string` | Primary key of record to update |
| `$data` | `array<string, mixed>` | Column => value pairs to update |

**Returns:** Updated model instance.

**Throws:** `RuntimeException` if record not found.

**Example:**

```php
$user = $repo->update(1, [
    'name' => 'John Updated',
    'status' => 'premium'
]);

echo $user->name; // "John Updated"
```

::: warning
This method throws an exception if the record doesn't exist. Use `updateBy()` if you want to handle non-existence gracefully.
:::

---

## updateBy()

Update multiple records matching criteria.

```php
public function updateBy(array $criteria, array $data): int
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$criteria` | `array<string, mixed>` | Conditions for selecting records |
| `$data` | `array<string, mixed>` | Column => value pairs to update |

**Returns:** Number of affected rows.

**Example:**

```php
// Deactivate all expired subscriptions
$affected = $repo->updateBy(
    ['expires_at' => ['<' => date('Y-m-d')]],
    ['status' => 'expired']
);

// Upgrade all trial users
$affected = $repo->updateBy(
    ['role' => 'trial'],
    ['role' => 'basic', 'upgraded_at' => date('Y-m-d H:i:s')]
);

// Update specific user's posts
$affected = $postRepo->updateBy(
    ['user_id' => 5, 'status' => 'draft'],
    ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')]
);
```

::: warning Self-relations are rejected
`updateBy()` (and `forceDeleteBy()`) refuse relation-criteria EXISTS subqueries that target the same table being modified — MySQL forbids referencing the UPDATE/DELETE target table inside a subquery's `FROM`. A clear `InvalidArgumentException` is thrown rather than letting the database fail mid-operation.

For self-referential criteria (e.g. `parent_id` on `categories`), fetch IDs first and update with a plain `IN`:

```php
$ids = array_column($repo->findBy(['children.name' => 'x']), 'id');
$repo->updateBy(['id' => $ids], ['archived' => 1]);
```
:::

---

## delete()

Delete a record by its primary key.

```php
public function delete(int|string $id): int
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$id` | `int\|string` | Primary key of record to delete |

**Returns:** Number of affected rows.

**Behavior:**
- **With soft delete enabled:** Sets `deleted_at` timestamp
- **Without soft delete:** Performs physical deletion

**Example:**

```php
// With soft delete: sets deleted_at = NOW()
$repo->delete(1);

// Without soft delete: physical removal
$repo->delete(1);
```

---

## deleteBy()

Delete multiple records matching criteria.

```php
public function deleteBy(array $criteria): int
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$criteria` | `array<string, mixed>` | Conditions for selecting records |

**Returns:** Number of affected rows.

**Example:**

```php
// Delete all inactive users (soft or hard based on config)
$affected = $repo->deleteBy([
    'status' => 'inactive',
    'last_login' => ['<' => '2023-01-01']
]);

// Delete all draft posts by user
$affected = $postRepo->deleteBy([
    'user_id' => 5,
    'status' => 'draft'
]);
```

---

## Pivot Methods

These methods manage BelongsToMany relations via pivot tables. The relation must be defined in `$relationConfig`.

### attach()

Add related IDs to a pivot table. Existing links are not duplicated.

```php
public function attach(string $relation, int|string $id, array $relatedIds): void
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$relation` | `string` | Relation name from `$relationConfig` |
| `$id` | `int\|string` | Parent model ID |
| `$relatedIds` | `list<int\|string>` | Related model IDs to attach |

**Example:**

```php
$articleRepo->attach('tags', $article->id, [1, 2, 3]);
```

### detach()

Remove related IDs from a pivot table. Without `$relatedIds`, detaches all.

```php
public function detach(string $relation, int|string $id, array $relatedIds = []): void
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$relation` | `string` | Relation name from `$relationConfig` |
| `$id` | `int\|string` | Parent model ID |
| `$relatedIds` | `list<int\|string>` | Related IDs to detach (empty = all) |

**Example:**

```php
// Detach specific tags
$articleRepo->detach('tags', $article->id, [1]);

// Detach all tags
$articleRepo->detach('tags', $article->id);
```

### sync()

Replace pivot records: keep only the given related IDs, remove the rest. Uses a diff-based approach (only inserts/deletes the difference).

```php
public function sync(string $relation, int|string $id, array $relatedIds): void
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$relation` | `string` | Relation name from `$relationConfig` |
| `$id` | `int\|string` | Parent model ID |
| `$relatedIds` | `list<int\|string>` | Related IDs that should remain |

**Example:**

```php
// After sync: article has only tags 2 and 3
$articleRepo->sync('tags', $article->id, [2, 3]);

// Remove all relations
$articleRepo->sync('tags', $article->id, []);
```

::: warning BelongsToMany Only
`attach()`, `detach()`, and `sync()` only work with BelongsToMany relations. Calling them on HasMany, HasOne, or BelongsTo will throw `InvalidArgumentException`.
:::

---

## seedTranslations()

Insert one translation row per locale from a single set of values, skipping
locales that already have a row. Available when `$translationConfig` is set.

```php
public function seedTranslations(int|string $id, array $locales, array $values): int
```

**Returns:** Number of rows actually inserted (existing locales are skipped).

**Example:**

```php
$product = $repo->create(['sku' => 'SKU-001', 'price' => 29.99]);
$repo->seedTranslations($product->id, ['uk', 'en', 'de'], [
    'name'        => 'Product 1',
    'description' => 'Initial description',
]);
```

See [Translations → Writing Translations](/features/translations#writing-translations)
for idempotency, field filtering, and cross-platform behavior.

---

## Soft Delete Methods

These methods are available when `$deletedAtColumn` is configured:

### forceDelete()

Permanently delete a record, bypassing soft delete.

```php
public function forceDelete(int|string $id): int
```

**Example:**

```php
// Permanently remove (even with soft delete enabled)
$repo->forceDelete(1);
```

### forceDeleteBy()

Permanently delete multiple records matching criteria.

```php
public function forceDeleteBy(array $criteria): int
```

**Example:**

```php
// Purge soft-deleted records older than 30 days
$repo->forceDeleteBy([
    'deleted_at' => ['<' => date('Y-m-d', strtotime('-30 days'))]
]);
```

### restore()

Restore a soft-deleted record.

```php
public function restore(int|string $id): int
```

**Example:**

```php
$repo->restore(1); // Sets deleted_at = NULL
```

See [Soft Delete](/features/soft-delete) for more details.
