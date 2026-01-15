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
The repository automatically detects whether to use auto-increment or your provided ID. No configuration needed.
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
- Records are inserted in chunks of 500 for large datasets
- The entire operation is wrapped in a transaction
- Returns total affected rows count
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
