# Retrieval Methods

## find()

Find a single record by its primary key.

```php
public function find(int|string $id): ?TModel
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$id` | `int\|string` | The primary key value |

**Returns:** Model instance or `null` if not found.

**Example:**

```php
$user = $userRepository->find(1);
$product = $productRepository->find('PROD-123'); // Custom ID

if ($user === null) {
    throw new NotFoundException('User not found');
}
```

::: tip
When soft delete is enabled, `find()` only returns non-deleted records.
:::

---

## findOneBy()

Find the first record matching the given criteria.

```php
public function findOneBy(
    array $criteria,
    ?array $orderBy = null
): ?TModel
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$criteria` | `array<string, mixed>` | Filtering conditions |
| `$orderBy` | `?array<string, 'ASC'\|'DESC'>` | Optional sorting |

**Returns:** Model instance or `null` if not found.

**Example:**

```php
// Find by unique field
$user = $repo->findOneBy(['email' => 'john@example.com']);

// Find latest matching record
$latestOrder = $repo->findOneBy(
    ['user_id' => 5, 'status' => 'completed'],
    ['created_at' => 'DESC']
);

// With operators
$premiumUser = $repo->findOneBy([
    'balance' => ['>' => 1000],
    'status' => 'active'
]);
```

---

## findAll()

Retrieve all records from the table.

```php
public function findAll(): list<TModel>
```

**Returns:** Array of all model instances.

**Example:**

```php
$allUsers = $userRepository->findAll();

foreach ($allUsers as $user) {
    echo $user->name;
}
```

::: warning Performance
Use with caution on large tables. Consider `findBy()` with pagination instead.
:::

---

## findBy()

Find records matching criteria with optional sorting and pagination.

```php
public function findBy(
    array $criteria,
    ?array $orderBy = null,
    ?int $perPage = null,
    ?int $page = null
): list<TModel>
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$criteria` | `array<string, mixed>` | Filtering conditions |
| `$orderBy` | `?array<string, 'ASC'\|'DESC'>` | Columns to sort by |
| `$perPage` | `?int` | Records per page (enables pagination) |
| `$page` | `?int` | Page number (1-indexed, defaults to 1) |

**Returns:** Array of matching model instances.

**Example:**

```php
// Simple filter
$activeUsers = $repo->findBy(['status' => 'active']);

// With sorting
$users = $repo->findBy(
    ['role' => 'admin'],
    ['created_at' => 'DESC']
);

// With pagination (20 items per page, page 3)
$users = $repo->findBy(
    ['status' => 'active'],
    ['name' => 'ASC'],
    20,  // perPage
    3    // page
);

// Complex criteria
$users = $repo->findBy([
    'status' => 'active',
    'age' => ['>=' => 18],
    'role' => ['admin', 'moderator'], // IN list
]);
```

### Pagination Example

```php
$perPage = 20;
$page = (int) ($_GET['page'] ?? 1);

$users = $repo->findBy(
    ['status' => 'active'],
    ['created_at' => 'DESC'],
    $perPage,
    $page
);

$total = $repo->count(['status' => 'active']);
$totalPages = ceil($total / $perPage);
```

---

## Using with Eager Loading

All retrieval methods work with `with()`:

```php
// Single relation
$posts = $repo->with(['user'])->findAll();

// Multiple relations
$posts = $repo->with(['user', 'comments'])->findBy(['status' => 'published']);

// Works with find() too
$post = $repo->with(['user', 'comments'])->find(1);

// Nested relations
$posts = $repo->with(['comments', 'comments.user'])->findAll();
```

See [Eager Loading](/features/eager-loading) for more details.
