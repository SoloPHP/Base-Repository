# Custom IDs

The repository automatically detects whether to use auto-increment or a custom ID based on whether you provide the primary key in the data array.

## Auto-Increment (Default)

When you don't provide an ID, the database generates one:

```php
$user = $repo->create([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

echo $user->id; // Auto-generated: 1, 2, 3, ...
```

## Custom ID

Provide your own ID in the data array:

```php
// UUID
$user = $repo->create([
    'id' => '550e8400-e29b-41d4-a716-446655440000',
    'name' => 'Jane Doe',
    'email' => 'jane@example.com'
]);

// Prefixed ID
$product = $repo->create([
    'id' => 'PROD-000001',
    'name' => 'Widget',
    'price' => 99.99
]);

// ULID
$order = $repo->create([
    'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'user_id' => 1,
    'total' => 150.00
]);
```

---

## UUID Examples

### Using ramsey/uuid

```bash
composer require ramsey/uuid
```

```php
use Ramsey\Uuid\Uuid;

$user = $repo->create([
    'id' => Uuid::uuid4()->toString(),
    'name' => 'Alice'
]);
// id: "550e8400-e29b-41d4-a716-446655440000"
```

### Using symfony/uid

```bash
composer require symfony/uid
```

```php
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\Ulid;

// UUID v4
$user = $repo->create([
    'id' => Uuid::v4()->toRfc4122(),
    'name' => 'Bob'
]);

// ULID (time-sortable)
$order = $repo->create([
    'id' => (new Ulid())->toBase32(),
    'total' => 99.99
]);
```

---

## Prefixed IDs

```php
class OrderRepository extends BaseRepository
{
    private int $sequence = 0;

    public function createOrder(array $data): Order
    {
        // Get next sequence (or use database sequence)
        $lastOrder = $this->findOneBy([], ['id' => 'DESC']);
        $nextNum = $lastOrder 
            ? ((int) substr($lastOrder->id, 4)) + 1 
            : 1;

        $data['id'] = 'ORD-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        
        return $this->create($data);
    }
}

// Usage
$order = $orderRepo->createOrder(['user_id' => 1, 'total' => 99.99]);
echo $order->id; // "ORD-000001"
```

---

## Custom Primary Key Column

If your table uses a different column name for the primary key:

```php
class OrderRepository extends BaseRepository
{
    protected string $primaryKey = 'order_id';  // Instead of 'id'

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, Order::class, 'orders');
    }
}

// Usage
$order = $repo->find('ORD-001');           // Uses order_id column
$repo->update('ORD-001', ['status' => 'shipped']);
$repo->delete('ORD-001');
```

---

## Database Considerations

### UUID Column Type

```sql
-- MySQL
CREATE TABLE users (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255)
);

-- PostgreSQL (native UUID type)
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255)
);

-- With index for performance
CREATE INDEX idx_users_created ON users(created_at);
```

### Binary UUID (More Efficient)

```sql
-- MySQL with binary storage
CREATE TABLE users (
    id BINARY(16) PRIMARY KEY,
    name VARCHAR(255)
);
```

```php
use Ramsey\Uuid\Uuid;

// Store as binary
$user = $repo->create([
    'id' => Uuid::uuid4()->getBytes(),
    'name' => 'Alice'
]);
```

---

## Best Practices

### When to Use UUIDs

✅ **Good for:**
- Distributed systems
- Preventing ID enumeration
- Merging data from multiple sources
- Public-facing IDs (URLs)

❌ **Consider alternatives when:**
- High insert volume (auto-increment is faster)
- Need sequential ordering
- Storage space is critical

### When to Use Prefixed IDs

✅ **Good for:**
- Human-readable identifiers
- Different entity types in URLs
- Customer-facing order numbers
- Import/export scenarios

```php
// Clear what type of entity
'/users/USR-000123'
'/orders/ORD-000456'
'/products/PROD-000789'
```

---

## Model Support

Ensure your model handles the ID type:

```php
class User
{
    public function __construct(
        public readonly string $id,  // String for UUID/custom
        public readonly string $name,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],  // Cast to string
            name: $data['name'],
        );
    }
}
```

For integer IDs:

```php
class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: $data['name'],
        );
    }
}
```
