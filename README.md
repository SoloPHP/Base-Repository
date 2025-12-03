## Solo Base Repository

[![Packagist Version](https://img.shields.io/packagist/v/solophp/base-repository.svg?style=flat-square)](https://packagist.org/packages/solophp/base-repository)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/base-repository.svg?style=flat-square)](https://packagist.org/packages/solophp/base-repository)
[![License](https://img.shields.io/packagist/l/solophp/base-repository.svg?style=flat-square)](LICENSE)
[![Code Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen.svg?style=flat-square)]()

Lightweight base repository with built-in soft delete and eager loading capabilities.

## Features

- Soft delete with configurable `deleted_at` column
- Eager loading via `relationConfig` (supports `hasMany`, `hasOne`, and `belongsTo`)
- Relation filtering with dot-notation (generates efficient `EXISTS (...)` and `NOT EXISTS (...)` subqueries)
- Rich criteria syntax: equality, NULL, IN lists, operators
- Pagination and sorting with safe identifier validation
- Transactions helper (`withTransaction`) and explicit transaction control
- Supports custom IDs (disable auto-increment) and bulk inserts
- Doctrine DBAL QueryBuilder under the hood with parameter binding

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
- **Custom IDs**: Set `protected bool $useAutoIncrement = false` to use custom IDs instead of auto-increment

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
| `$useAutoIncrement` | bool | `true` | Whether to use auto-increment IDs or custom IDs |

### Criteria Syntax

| Pattern | Example | SQL |
|---|---|---|
| Equality | `['status' => 'active']` | `status = ?` |
| Null | `['deleted_at' => null]` | `deleted_at IS NULL` |
| IN (list) | `['id' => [1,2,3]]` | `id IN (?, ?, ?)` |
| Operator `=` | `['status' => ['=', 'active']]` | `status = ?` |
| Operator `!=` | `['status' => ['!=', 'draft']]` | `status != ?` |
| Operator `<>` | `['status' => ['<>', 'draft']]` | `status <> ?` |
| Operator `<` | `['age' => ['<', 18]]` | `age < ?` |
| Operator `>` | `['age' => ['>', 18]]` | `age > ?` |
| Operator `<=` | `['age' => ['<=', 65]]` | `age <= ?` |
| Operator `>=` | `['age' => ['>=', 18]]` | `age >= ?` |
| Operator `LIKE` | `['name' => ['LIKE', '%john%']]` | `name LIKE ?` |
| Operator `NOT LIKE` | `['name' => ['NOT LIKE', '%test%']]` | `name NOT LIKE ?` |
| Operator `IN` | `['status' => ['IN', ['a', 'b']]]` | `status IN ?` |
| Operator `NOT IN` | `['status' => ['NOT IN', ['x', 'y']]]` | `status NOT IN ?` |
| Null via operator | `['deleted_at' => ['=', null]]` | `deleted_at IS NULL` |
| Not Null via operator | `['deleted_at' => ['!=', null]]` | `deleted_at IS NOT NULL` |

### Relation Filters (Dot-notation)

You can filter by related entities using dot-notation keys inside `criteria`. The repository will generate efficient `EXISTS (...)` subqueries based on your `relationConfig`.

Examples (assuming `relationConfig` defines `comments` as `hasMany` and `user` as `belongsTo`):

```php
// hasMany: posts that have at least one comment with status = 'approved'
$posts = $repo->findBy([
    'comments.status' => 'approved',
]);

// belongsTo: posts whose user role is 'admin'
$posts = $repo->findBy([
    'user.role' => 'admin',
]);

// Multiple conditions across relations are combined with AND
$posts = $repo->findBy([
    'comments.status' => 'approved',
    'user.role' => 'admin',
]);

// IN lists and operators are supported
$posts = $repo->findBy([
    'comments.type' => ['review', 'question'],            // IN (...)
    'comments.created_at' => ['>=', '2024-01-01 00:00:00'], // operator
]);

// Null checks
$posts = $repo->findBy([
    'comments.deleted_at' => null, // IS NULL
]);

// Null checks via operator
$posts = $repo->findBy([
    'comments.deleted_at' => ['=', null],   // IS NULL
]);

// Not-null checks via operator
$posts = $repo->findBy([
    'comments.deleted_at' => ['!=', null],  // IS NOT NULL
    // or ['<>', null]
]);

// NOT EXISTS: posts that have NO comments with status = 'approved'
$posts = $repo->findBy([
    '!comments.status' => 'approved',  // Use ! prefix for NOT EXISTS
]);

// NOT EXISTS: posts that have NO comments at all
$posts = $repo->findBy([
    '!comments.id' => ['>', 0],  // Any condition with ! prefix creates NOT EXISTS
]);

// Combining EXISTS and NOT EXISTS
$posts = $repo->findBy([
    'user.role' => 'admin',           // EXISTS: has user with role = 'admin'
    '!comments.status' => 'spam',     // NOT EXISTS: has no spam comments
]);
```

Notes:
- Relation types supported: `hasMany`, `hasOne`, `belongsTo`.
- Column linkage is derived from `relationConfig` (`[type, repositoryProperty, foreignKey, ...]`).
- Use `!` prefix before relation name (e.g., `!comments.field`) to generate `NOT EXISTS` instead of `EXISTS`.
- An empty IN list short-circuits to a non-matching condition.
- If a relation is present in criteria with an empty filter set, it is treated as a pure existence check (EXISTS without extra predicates).
- For safety and portability, filters are applied with parameters; table/column identifiers and generated aliases are validated/sanitized.
- Internally uses raw `EXISTS ( ... )` for compatibility across Doctrine DBAL versions (see Expressions guidance in Doctrine DBAL docs).

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
| `sum(string $column, array $criteria = []): int\|float` | Sum of column values |
| `avg(string $column, array $criteria = []): int\|float` | Average of column values |
| `min(string $column, array $criteria = []): mixed` | Minimum value |
| `max(string $column, array $criteria = []): mixed` | Maximum value |

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
| `restore(int\|string $id): int` | Restore soft-deleted record |
| `forceDelete(int\|string $id): int` | Hard delete bypassing soft delete |
| `forceDeleteBy(array $criteria): int` | Hard delete by criteria bypassing soft delete |

### Examples
```php
// Safe behavior by default (only active records)
$users = $repo->findAll();                    // Only active records
$repo->delete(1);                            // Soft delete (sets deleted_at)

// Hard delete (physical removal)
$repo->forceDelete(1);                       // Physical deletion

// Restore soft-deleted records
$repo->restore(1);                           // Sets deleted_at = NULL

// Filter by deleted_at column directly
$deleted = $repo->findBy(['deleted_at' => ['!=', null]]);  // Only soft-deleted
$active = $repo->findBy([]);                               // Only active (default)
$all = $repo->findBy(['deleted_at' => '*']);               // All records (including deleted)
```

## Custom ID Support

By default, the repository uses auto-increment IDs via `lastInsertId()`. For tables with custom IDs (UUIDs, prefixed IDs, etc.), disable auto-increment:

```php
class ProductRepository extends BaseRepository
{
    protected bool $useAutoIncrement = false; // Disable auto-increment

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, Product::class, 'products');
    }
}
```

### Usage with Custom IDs

```php
// Custom ID must be provided when auto-increment is disabled
$product = $repo->create([
    'id' => 'PROD-123',
    'name' => 'Custom Product',
    'price' => 99.99
]);

// Works with UUIDs too
$user = $userRepo->create([
    'id' => 'uuid-4e8c-9f7a-2b1d-3e5a6b7c8d9e',
    'email' => 'user@example.com'
]);
```

### Validation
When `$useAutoIncrement = false`, the primary key must be provided in the data array, otherwise an `InvalidArgumentException` is thrown:

```php
// This will throw an exception if $useAutoIncrement = false
$repo->create(['name' => 'Product']); // Missing 'id'
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

- **type**: `'belongsTo'`, `'hasOne'`, or `'hasMany'`
- **repositoryProperty**: Property name of related repository on current repository
- **foreignKey**: Foreign key column name
- **setterMethod**: Method to call on model to set the relation
- **sort**: Optional sorting for `hasMany` and `hasOne` relations

### Relation Types

#### belongsTo
The model has a foreign key pointing to the related model's primary key.
```php
// User belongs to Company (users.company_id -> companies.id)
protected array $relationConfig = [
    'company' => ['belongsTo', 'companyRepository', 'company_id', 'setCompany']
];
```

#### hasOne
The related model has a foreign key pointing to this model's primary key. Returns a single object or null.
```php
// User has one Profile (profiles.user_id -> users.id)
protected array $relationConfig = [
    'profile' => ['hasOne', 'profileRepository', 'user_id', 'setProfile']
];
```

#### hasMany
The related model has a foreign key pointing to this model's primary key. Returns an array of objects.
```php
// Post has many Comments (comments.post_id -> posts.id)
protected array $relationConfig = [
    'comments' => ['hasMany', 'commentRepository', 'post_id', 'setComments', ['created_at' => 'ASC']]
];
```

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
$activePosts = $repo->with(['user'])->findAll();                                  // Active posts with users
$deletedPosts = $repo->with(['user'])->findBy(['deleted_at' => ['!=', null]]);    // Deleted posts with users
$allPosts = $repo->with(['user'])->findBy(['deleted_at' => '*']);                 // All posts with users
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

// Transactions
$repo->withTransaction(function (UserRepository $r) {
    $user = $r->create(['name' => 'Temp', 'email' => 'temp@example.com']);
    $r->update($user->id, ['name' => 'Temp Updated']);
});

// Aggregation
$total = $repo->sum('amount', ['status' => 'paid']);
$average = $repo->avg('score', ['active' => true]);
$minPrice = $repo->min('price');
$maxPrice = $repo->max('price');
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