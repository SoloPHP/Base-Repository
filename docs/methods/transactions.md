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

Start a new database transaction.

```php
public function beginTransaction(): bool
```

**Returns:** `true` if transaction is active.

### commit()

Commit the current transaction.

```php
public function commit(): bool
```

**Returns:** `true` on success.

### rollBack()

Roll back the current transaction.

```php
public function rollBack(): bool
```

**Returns:** `true` on success.

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
