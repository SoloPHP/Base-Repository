# Aggregate Methods

All aggregate methods respect soft delete settings when enabled.

## exists()

Check if any record exists matching the criteria.

```php
public function exists(array $criteria): bool
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$criteria` | `array<string, mixed>` | Filtering conditions |

**Returns:** `true` if at least one matching record exists.

**Example:**

```php
// Check if email is taken
if ($repo->exists(['email' => $email])) {
    throw new Exception('Email already taken');
}

// Check if user has any orders
if ($orderRepo->exists(['user_id' => $userId])) {
    echo "User has orders";
}

// Check with operators
if ($repo->exists(['balance' => ['>' => 0]])) {
    echo "Has positive balance";
}
```

::: tip Performance
More efficient than `findOneBy()` when you only need to check existence — doesn't hydrate a model.
:::

---

## count()

Count records matching the criteria.

```php
public function count(array $criteria): int
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$criteria` | `array<string, mixed>` | Filtering conditions |

**Returns:** Number of matching records.

**Example:**

```php
// Count active users
$totalActive = $repo->count(['status' => 'active']);

// Count admins
$totalAdmins = $repo->count(['role' => 'admin']);

// Count all (non-deleted)
$total = $repo->count([]);

// Count with operators
$premiumCount = $repo->count(['balance' => ['>=' => 1000]]);
```

### Pagination Example

```php
$perPage = 20;
$page = (int) ($_GET['page'] ?? 1);
$criteria = ['status' => 'active'];

$users = $repo->findBy($criteria, ['name' => 'ASC'], $perPage, $page);
$total = $repo->count($criteria);
$totalPages = (int) ceil($total / $perPage);

echo "Page {$page} of {$totalPages} ({$total} total users)";
```

---

## sum()

Calculate the sum of a numeric column.

```php
public function sum(string $column, array $criteria = []): int|float
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$column` | `string` | Column to sum |
| `$criteria` | `array<string, mixed>` | Optional filtering conditions |

**Returns:** Sum of column values (returns `0` if no matching records).

**Example:**

```php
// Total revenue from paid orders
$totalRevenue = $orderRepo->sum('amount', ['status' => 'paid']);

// User's total transactions
$userBalance = $transactionRepo->sum('amount', ['user_id' => 5]);

// Sum all (no criteria)
$totalStock = $productRepo->sum('quantity');
```

---

## avg()

Calculate the average of a numeric column.

```php
public function avg(string $column, array $criteria = []): int|float
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$column` | `string` | Column to average |
| `$criteria` | `array<string, mixed>` | Optional filtering conditions |

**Returns:** Average value (returns `0` if no matching records).

**Example:**

```php
// Average product rating
$avgRating = $reviewRepo->avg('rating', ['product_id' => 42]);

// Average order value
$avgOrderValue = $orderRepo->avg('total', ['status' => 'completed']);

// Average age of active users
$avgAge = $userRepo->avg('age', ['status' => 'active']);
```

---

## min()

Get the minimum value of a column.

```php
public function min(string $column, array $criteria = []): mixed
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$column` | `string` | Column to find minimum |
| `$criteria` | `array<string, mixed>` | Optional filtering conditions |

**Returns:** Minimum value (type depends on column).

**Example:**

```php
// Lowest price in category
$lowestPrice = $productRepo->min('price', ['category' => 'electronics']);

// Earliest order date
$firstOrderDate = $orderRepo->min('created_at');

// Minimum stock level
$minStock = $productRepo->min('quantity', ['status' => 'active']);
```

---

## max()

Get the maximum value of a column.

```php
public function max(string $column, array $criteria = []): mixed
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$column` | `string` | Column to find maximum |
| `$criteria` | `array<string, mixed>` | Optional filtering conditions |

**Returns:** Maximum value (type depends on column).

**Example:**

```php
// Highest price
$maxPrice = $productRepo->max('price');

// Latest login time for active users
$lastLogin = $userRepo->max('last_login_at', ['status' => 'active']);

// Highest order number
$lastOrderNum = $orderRepo->max('order_number');
```

---

## Combining Aggregates

```php
// Product statistics
$stats = [
    'total' => $productRepo->count(['category' => 'electronics']),
    'min_price' => $productRepo->min('price', ['category' => 'electronics']),
    'max_price' => $productRepo->max('price', ['category' => 'electronics']),
    'avg_price' => $productRepo->avg('price', ['category' => 'electronics']),
    'total_stock' => $productRepo->sum('quantity', ['category' => 'electronics']),
];

// Order statistics for user
$userStats = [
    'order_count' => $orderRepo->count(['user_id' => $userId]),
    'total_spent' => $orderRepo->sum('total', ['user_id' => $userId, 'status' => 'paid']),
    'avg_order' => $orderRepo->avg('total', ['user_id' => $userId, 'status' => 'paid']),
    'first_order' => $orderRepo->min('created_at', ['user_id' => $userId]),
    'last_order' => $orderRepo->max('created_at', ['user_id' => $userId]),
];
```
