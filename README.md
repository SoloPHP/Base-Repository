## Solo Base Repository

[![Packagist Version](https://img.shields.io/packagist/v/solophp/base-repository.svg?style=flat-square)](https://packagist.org/packages/solophp/base-repository)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/base-repository.svg?style=flat-square)](https://packagist.org/packages/solophp/base-repository)
[![License](https://img.shields.io/packagist/l/solophp/base-repository.svg?style=flat-square)](LICENSE)

Lightweight base repository with built-in soft delete and eager loading capabilities.
- **`RepositoryInterface<TModel>`**: generic contract for CRUD, aggregates, pagination, and transactions.
- **`BaseRepository<TModel>`**: ready-to-extend base with criteria parsing, sorting, mapping, soft delete, and eager loading.

### Installation
```bash
composer require solophp/base-repository
```

### Requirements
- PHP 8.3+
- Doctrine DBAL (`doctrine/dbal` ^4.3)

### Quick Start

```php
use Solo\BaseRepository\BaseRepository;
use Doctrine\DBAL\Connection;

// Basic repository (no additional features)
class LogRepository extends BaseRepository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, Log::class, 'logs');
    }
}

// Repository with soft delete
class UserRepository extends BaseRepository
{
    protected string $deletedAtColumn = 'deleted_at'; // Enable soft delete

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, User::class, 'users');
    }
}

// Repository with soft delete and eager loading
class PostRepository extends BaseRepository
{
    protected string $deletedAtColumn = 'deleted_at'; // Enable soft delete
    protected array $relationConfig = [               // Enable eager loading
        'user' => ['belongsTo', 'userRepository', 'user_id', 'setUser'],
        'comments' => ['hasMany', 'commentRepository', 'post_id', 'setComments']
    ];

    public function __construct(Connection $connection, UserRepository $userRepo, CommentRepository $commentRepo)
    {
        parent::__construct($connection, Post::class, 'posts');
        $this->userRepository = $userRepo;
        $this->commentRepository = $commentRepo;
    }
}
```

## Features

### Auto-Configuration
Features are automatically enabled based on configuration:
- **Soft Delete**: Define `protected string $deletedAtColumn` to enable
- **Eager Loading**: Define `protected array $relationConfig` to enable

### Constructor
```php
__construct(
    protected Connection $connection,
    string $modelClass,
    string $table,
    ?string $tableAlias = null,
    string $mapperMethod = 'fromArray'
)
```

### Configurable Properties

| Property | Type | Default | Description |
|---|---|---|---|
| `$primaryKey` | string | `'id'` | Primary key column |
| `$tableAlias` | ?string | `null` | Table alias (defaults to first letter of table name) |
| `$table` | string | - | Database table name (constructor parameter) |
| `$modelClass` | string | - | Model class name (constructor parameter) |
| `$mapperMethod` | string | `'fromArray'` | Static method for mapping array to model |
| `$connection` | Connection | - | Doctrine DBAL connection (constructor parameter) |
| `$deletedAtColumn` | ?string | `null` | Soft-delete timestamp column (enables soft delete) |
| `$relationConfig` | array | `[]` | Relations configuration (enables eager loading) |

### Criteria Syntax

| Pattern | Example | SQL |
|---|---|---|
| Equality | `['status' => 'active']` | `status = ?` |
| Null | `['deleted_at' => null]` | `deleted_at IS NULL` |
| IN (list) | `['id' => [1,2,3]]` | `id IN (?, ?, ?)` |
| Operator | `['age' => ['>', 18]]` | `age > ?` |
| Search | `['search' => ['name' => 'John', 'email' => 'example']]` | `name LIKE ? AND email LIKE ?` |
| Deleted filter | `['deleted' => 'only']` or `['deleted' => 'with']` | Filter soft-deleted records |

### Retrieval Methods

| Method | Description |
|---|---|
| `find(int\|string $id): ?TModel` | Get model by primary key |
| `findOneBy(array $criteria, ?array $orderBy = null): ?TModel` | First by criteria and sort |
| `findAll(): list<TModel>` | All rows |
| `findBy(array $criteria, ?array $orderBy = null, ?int $perPage = null, ?int $page = null): list<TModel>` | Filtered list, optional pagination |

### Mutation Methods

| Method | Description |
|---|---|
| `create(array $data): TModel` | Create and return model object |
| `insertMany(list<array<string,mixed>> $records): int` | Bulk insert, returns affected rows |
| `update(int\|string $id, array $data): TModel` | Update by ID and return model |
| `updateBy(array $criteria, array $data): int` | Update by criteria |
| `delete(int\|string $id): int` | Soft or hard delete by ID |
| `deleteBy(array $criteria): int` | Soft or hard delete by criteria |

### Existence and Aggregates

| Method | Description |
|---|---|
| `exists(array $criteria): bool` | Check existence |
| `count(array $criteria): int` | Count rows |

## Soft Delete

Enable soft delete by defining `$deletedAtColumn`:

```php
class UserRepository extends BaseRepository
{
    protected string $deletedAtColumn = 'deleted_at'; // Enables soft delete
}
```

### Soft Delete Methods

| Method | Description |
|---|---|
| `['deleted' => 'with']` | Include soft-deleted records |
| `restore(int\|string $id): int` | Restore soft-deleted record |
| `forceDelete(int\|string $id): int` | Hard delete bypassing soft delete |
| `forceDeleteBy(array $criteria): int` | Hard delete by criteria bypassing soft delete |

### Examples
```php
// Safe behavior by default (only active records)
$users = $repo->findAll();                    // Only active records
$repo->delete(1);                            // Soft delete (sets deleted_at)

// Include soft-deleted records
$allUsers = $repo->findBy(['deleted' => 'with']); // All including soft-deleted

// Hard delete (physical removal)
$repo->forceDelete(1);                       // Physical deletion

// Restore soft-deleted records
$repo->restore(1);                           // Sets deleted_at = NULL

// API filtering
$deleted = $repo->findBy(['deleted' => 'only']);     // Only soft-deleted
$all = $repo->findBy(['deleted' => 'with']);         // All including soft-deleted
$active = $repo->findBy(['deleted' => 'without']);   // Only active (default)
```

## Eager Loading

Enable eager loading by defining `$relationConfig`:

```php
class PostRepository extends BaseRepository
{
    protected array $relationConfig = [
        'user' => ['belongsTo', 'userRepository', 'user_id', 'setUser'],
        'comments' => ['hasMany', 'commentRepository', 'post_id', 'setComments', ['id' => 'ASC']]
    ];

    public function __construct(Connection $connection, UserRepository $userRepo, CommentRepository $commentRepo)
    {
        parent::__construct($connection, Post::class, 'posts');
        $this->userRepository = $userRepo;
        $this->commentRepository = $commentRepo;
    }
}
```

### Relation Configuration Format
```php
'relationName' => [type, repositoryProperty, foreignKey, setterMethod, ?sort]
```

- **type**: `'belongsTo'` or `'hasMany'`
- **repositoryProperty**: Property name of related repository on current repository
- **foreignKey**: Foreign key column name
- **setterMethod**: Method to call on model to set the relation
- **sort**: Optional sorting for hasMany relations

### Usage
```php
// Load single relation
$posts = $repo->with(['user'])->findAll();

// Load multiple relations
$posts = $repo->with(['user', 'comments'])->findBy(['status' => 'published']);

// Nested relations via dot-notation
// Example domain: products -> productAttributes (hasMany) -> attribute (belongsTo)
$products = $productRepo
    ->with(['productAttributes', 'productAttributes.attribute'])
    ->findAll();

// Works with all find methods
$post = $repo->with(['user', 'comments'])->find(1);
$post = $repo->with(['user'])->findOneBy(['slug' => 'my-post']);
```

## Combining Features

Both soft delete and eager loading can be used together:

```php
class PostRepository extends BaseRepository
{
    protected string $deletedAtColumn = 'deleted_at';  // Enable soft delete
    protected array $relationConfig = [                // Enable eager loading
        'user' => ['belongsTo', 'userRepository', 'user_id', 'setUser']
    ];
}

// Usage
$activePosts = $repo->with(['user'])->findAll();                    // Active posts with users
$allPosts = $repo->with(['user'])->findBy(['deleted' => 'with']);   // All posts with users
$deletedPosts = $repo->with(['user'])->findBy(['deleted' => 'only']); // Deleted posts with users
```

### Transactions

| Method | Description |
|---|---|
| `beginTransaction(): bool` | Begin transaction |
| `commit(): bool` | Commit |
| `rollBack(): bool` | Rollback |
| `inTransaction(): bool` | Transaction state |
| `withTransaction(callable $cb): mixed` | Execute callback in transaction |

### Example Usage

```php
// Basic filtering and sorting with pagination
$users = $repo->findBy(
    ['status' => 'active', 'age' => ['>', 18]],
    ['created_at' => 'DESC'],
    20,  // perPage
    1    // page
);

// Search queries (LIKE)
$filtered = $repo->findBy([
    'search' => ['name' => 'john', 'email' => 'example.com']
]);

// Transactions
$repo->withTransaction(function (UserRepository $r) {
    $user = $r->create(['name' => 'Temp', 'email' => 'temp@example.com']);
    $r->update($user->id, ['name' => 'Temp Updated']);
});
```

### Extending Repositories

Add domain-specific methods using `table()` and builder chaining:

```php
final class UserRepository extends BaseRepository
{
    protected string $deletedAtColumn = 'deleted_at';

    public function findTopActive(int $limit = 10): array
    {
        $rows = $this->table()
            ->andWhere('status = :status')
            ->setParameter('status', 'active')
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn(array $r) => $this->mapRowToModel($r), $rows);
    }
}
```

### Notes
- Features are automatically enabled based on configuration properties
- Soft delete logic integrates seamlessly with criteria syntax
- Eager loading works with soft delete enabled repositories
- Validate user-provided fields against whitelists for security

### License

This library is released under the MIT License. See the `LICENSE` file for details.