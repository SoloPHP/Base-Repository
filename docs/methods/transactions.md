# Transactions

## withTransaction()

Execute a callback within a database transaction with automatic commit/rollback.

```php
public function withTransaction(callable $callback): mixed
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$callback` | `callable` | Function to execute. Receives the repository as argument. |

**Returns:** The callback's return value.

**Behavior:**
- Automatically commits on success
- Automatically rolls back on any exception
- Re-throws the exception after rollback

**Example:**

```php
// Simple transaction
$user = $userRepo->withTransaction(function ($repo) {
    $user = $repo->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $repo->update($user->id, ['status' => 'verified']);
    return $user;
});

// With multiple operations
$result = $orderRepo->withTransaction(function ($repo) use ($userId, $items) {
    $order = $repo->create([
        'user_id' => $userId,
        'status' => 'pending',
        'total' => 0
    ]);
    
    $total = 0;
    foreach ($items as $item) {
        $this->orderItemRepo->create([
            'order_id' => $order->id,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'price' => $item['price']
        ]);
        $total += $item['quantity'] * $item['price'];
    }
    
    return $repo->update($order->id, ['total' => $total]);
});
```

### Exception Handling

```php
try {
    $userRepo->withTransaction(function ($repo) {
        $repo->create(['name' => 'Bob', 'email' => 'bob@example.com']);
        
        // This will cause rollback
        throw new Exception('Something went wrong!');
    });
} catch (Exception $e) {
    // Bob was NOT created - transaction rolled back
    echo "Error: " . $e->getMessage();
}
```

---

## Manual Transaction Control

For more complex scenarios, use explicit transaction methods:

### beginTransaction()

Start a new database transaction. Throws `Doctrine\DBAL\Exception` on driver failure.

```php
public function beginTransaction(): void
```

### commit()

Commit the current transaction. Throws `Doctrine\DBAL\Exception` if no transaction is active or the commit fails.

```php
public function commit(): void
```

### rollBack()

Roll back the current transaction. Throws `Doctrine\DBAL\Exception` if no transaction is active.

```php
public function rollBack(): void
```

### inTransaction()

Check if a transaction is currently active.

```php
public function inTransaction(): bool
```

**Returns:** `true` if inside a transaction.

---

## Manual Transaction Example

```php
$userRepo->beginTransaction();

try {
    // Create user
    $user = $userRepo->create([
        'name' => 'Alice',
        'email' => 'alice@example.com'
    ]);
    
    // Create related profile
    $profileRepo->create([
        'user_id' => $user->id,
        'bio' => 'Hello world'
    ]);
    
    // Create initial wallet
    $walletRepo->create([
        'user_id' => $user->id,
        'balance' => 0
    ]);
    
    $userRepo->commit();
    echo "User created successfully!";
    
} catch (Exception $e) {
    $userRepo->rollBack();
    echo "Error: " . $e->getMessage();
    // All operations rolled back
}
```

---

## lockForUpdate()

Lock one or more rows by primary key using `SELECT ... FOR UPDATE`. Must be called inside a transaction.

```php
public function lockForUpdate(int|string|array $id): void
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$id` | `int\|string\|array` | Single ID or array of IDs to lock |

**Behavior:**
- Executes `SELECT pk FROM table WHERE pk IN (?) FOR UPDATE` (single ID is normalized to a one-element list)
- Empty array is a no-op
- Must be called within an active transaction

**Example:**

```php
// Lock a single row
$repo->withTransaction(function ($repo) use ($userId) {
    $repo->lockForUpdate($userId);

    $user = $repo->find($userId);
    $repo->update($userId, [
        'balance' => $user->balance - 100
    ]);
});

// Lock multiple rows
$repo->withTransaction(function ($repo) use ($ids) {
    $repo->lockForUpdate($ids);

    $repo->updateBy(
        ['id' => $ids],
        ['status' => 'processing']
    );
});
```

::: warning Database Support
`SELECT ... FOR UPDATE` is supported by MySQL/MariaDB, PostgreSQL, and Oracle. SQLite does not support row-level locking.
:::

---

## withLock()

Run a callback while holding a cross-process **advisory (named) lock** scoped to a single
record, then release it. Unlike `lockForUpdate()`, it does **not** require the row to exist and
does **not** need a surrounding transaction — making it the tool for idempotency: ensuring the
same critical section for the same record never runs concurrently, even when reached from
multiple paths (a background job, its retry, a manual call).

```php
public function withLock(int|string $id, callable $callback, int $timeout = 10): mixed
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$id` | `int\|string` | Record id the critical section applies to |
| `$callback` | `callable` | Receives the repository; its return value is returned by `withLock()` |
| `$timeout` | `int` | Seconds to wait for the lock before failing (default `10`) |

**Behavior:**
- Acquires the lock → runs `$callback($this)` → returns its result → releases the lock.
- The lock is **always released**, including when `$callback` throws.
- If the lock is held by another process, waits up to `$timeout` seconds; on expiry throws
  `Solo\BaseRepository\LockTimeoutException` (it does **not** silently continue).
- The lock key encodes `database + table + id`, so locks for different tables, ids, or tenants
  (databases on the same server) never collide.

**Example:**

```php
use Solo\BaseRepository\LockTimeoutException;

try {
    $invoice = $invoiceRepo->withLock($orderId, function ($repo) use ($orderId) {
        // Runs once per order even if the job and its retry overlap.
        if ($repo->findOneBy(['order_id' => $orderId]) !== null) {
            return null; // already processed
        }
        return $repo->create(['order_id' => $orderId, 'status' => 'issued']);
    }, timeout: 5);
} catch (LockTimeoutException $e) {
    // Someone else holds the lock — re-queue and try later.
}
```

::: warning Database Support
Backed by `GET_LOCK` on MySQL/MariaDB and `pg_advisory_lock` on PostgreSQL. Other platforms
(SQLite, Oracle, SQL Server, DB2) throw `\RuntimeException` — advisory locking is not implemented
for them. The lock is tied to the database session/connection and is released automatically if
that connection drops.
:::

---

## Cross-Repository Transactions

All repositories share the same connection, so transactions work across them:

```php
$userRepo->withTransaction(function ($userRepo) use ($orderRepo, $paymentRepo) {
    $user = $userRepo->find(1);
    
    $order = $orderRepo->create([
        'user_id' => $user->id,
        'total' => 99.99
    ]);
    
    $paymentRepo->create([
        'order_id' => $order->id,
        'amount' => 99.99,
        'status' => 'pending'
    ]);
    
    $userRepo->update($user->id, [
        'last_order_at' => date('Y-m-d H:i:s')
    ]);
    
    return $order;
});
```

---

## Best Practices

::: tip Use withTransaction()
Prefer `withTransaction()` over manual control — it handles commit/rollback automatically and is less error-prone.
:::

::: warning Keep Transactions Short
- Don't include slow operations (API calls, file I/O) inside transactions
- Keep the transaction scope as small as possible
- Long-running transactions can cause lock contention
:::

```php
// ❌ Bad: External API call inside transaction
$repo->withTransaction(function ($repo) {
    $order = $repo->create([...]);
    $this->paymentGateway->charge($order->total); // Slow!
    $repo->update($order->id, ['paid' => true]);
});

// ✅ Good: API call outside transaction
$order = $repo->withTransaction(function ($repo) {
    return $repo->create([...]);
});

$paymentResult = $this->paymentGateway->charge($order->total);

if ($paymentResult->success) {
    $repo->update($order->id, ['paid' => true]);
}
```
