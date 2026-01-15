# Quick Start

## Create a Model

Your model class must have a static `fromArray()` method:

```php
class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $status,
        public readonly ?string $deletedAt = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: $data['name'],
            email: $data['email'],
            status: $data['status'],
            deletedAt: $data['deleted_at'] ?? null,
        );
    }
}
```

## Create a Repository

Extend `BaseRepository` and call the parent constructor:

```php
use Solo\BaseRepository\BaseRepository;
use Doctrine\DBAL\Connection;

class UserRepository extends BaseRepository
{
    public function __construct(Connection $connection)
    {
        parent::__construct(
            $connection,      // Doctrine DBAL connection
            User::class,      // Model class
            'users'           // Table name
        );
    }
}
```

## Basic Usage

```php
// Create connection
$connection = DriverManager::getConnection([
    'dbname' => 'mydb',
    'user' => 'root',
    'password' => '',
    'host' => 'localhost',
    'driver' => 'pdo_mysql',
]);

// Create repository
$userRepository = new UserRepository($connection);

// Find by ID
$user = $userRepository->find(1);

// Find by criteria
$activeUsers = $userRepository->findBy(['status' => 'active']);

// Create
$user = $userRepository->create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'status' => 'active'
]);

// Update
$user = $userRepository->update($user->id, ['name' => 'John Updated']);

// Delete
$userRepository->delete($user->id);
```

## Enable Soft Delete

Add the `$deletedAtColumn` property:

```php
class UserRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, User::class, 'users');
    }
}
```

Now `delete()` will set `deleted_at` instead of removing the record:

```php
$userRepository->delete(1);           // Sets deleted_at = NOW()
$userRepository->restore(1);          // Sets deleted_at = NULL
$userRepository->forceDelete(1);      // Physical deletion
```

## Enable Eager Loading

See [Eager Loading](/features/eager-loading) for detailed setup.

## Next Steps

- [Configuration](/guide/configuration) — All configuration options
- [Criteria Syntax](/features/criteria) — Learn filtering syntax
- [Soft Delete](/features/soft-delete) — Soft delete in detail
