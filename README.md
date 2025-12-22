## Solo Base Repository

[![Packagist Version](https://img.shields.io/packagist/v/solophp/base-repository.svg?style=flat-square)](https://packagist.org/packages/solophp/base-repository)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/base-repository.svg?style=flat-square)](https://packagist.org/packages/solophp/base-repository)
[![License](https://img.shields.io/packagist/l/solophp/base-repository.svg?style=flat-square)](LICENSE)
[![Code Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen.svg?style=flat-square)]()

Lightweight base repository with built-in soft delete and eager loading capabilities.

## Features

- Soft delete with configurable `deleted_at` column
- Eager loading via `relationConfig` (supports `belongsTo`, `hasOne`, `hasMany`, and `belongsToMany`)
- Relation filtering with dot-notation (generates efficient `EXISTS (...)` and `NOT EXISTS (...)` subqueries)
- Rich criteria syntax: equality, NULL, IN lists, operators
- Pagination and sorting with safe identifier validation
- Transactions helper (`withTransaction`) and explicit transaction control
- Supports custom IDs (UUIDs, prefixed IDs, etc.) and bulk inserts
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
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\HasMany;
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
    protected ?string $deletedAtColumn = 'deleted_at';

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, User::class, 'users');
    }
}

// Repository with soft delete and eager loading
class PostRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';
    protected array $relationConfig = [];

    public UserRepository $userRepository;
    public CommentRepository $commentRepository;

    public function __construct(
        Connection $connection,
        UserRepository $userRepo,
        CommentRepository $commentRepo
    ) {
        $this->userRepository = $userRepo;
        $this->commentRepository = $commentRepo;
        $this->relationConfig = [
            'user' => new BelongsTo(
                repository: 'userRepository',
                foreignKey: 'user_id',
                setter: 'setUser',
            ),
            'comments' => new HasMany(
                repository: 'commentRepository',
                foreignKey: 'post_id',
                setter: 'setComments',
            ),
        ];
        parent::__construct($connection, Post::class, 'posts');
    }
}
```

## Features

### Auto-Configuration
Features are automatically enabled based on configuration:
- **Soft Delete**: Define `protected string $deletedAtColumn` to enable
- **Eager Loading**: Define `protected array $relationConfig` to enable
- **Custom IDs**: Simply pass your custom ID in `create()` data array

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
- Relation types supported: `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`.
- Column linkage is derived from `relationConfig` (DTO objects).
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

The repository automatically detects whether to use auto-increment or a custom ID. If you provide the primary key in the data array, that value is used. Otherwise, `lastInsertId()` is called.

```php
// Auto-increment: ID is generated by the database
$user = $repo->create([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
// $user->id will be the auto-generated ID

// Custom ID: provide your own ID (UUID, prefixed ID, etc.)
$product = $repo->create([
    'id' => 'PROD-123',
    'name' => 'Custom Product',
    'price' => 99.99
]);
// $product->id will be 'PROD-123'

// Works with UUIDs too
$item = $repo->create([
    'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
    'name' => 'UUID Item'
]);
```

### Custom Primary Key Name

For tables with a different primary key column name, override the `$primaryKey` property:

```php
class OrderRepository extends BaseRepository
{
    protected string $primaryKey = 'order_id';  // instead of default 'id'

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, Order::class, 'orders');
    }
}

// Usage
$order = $repo->find('ORD-001');           // uses order_id column
$repo->update('ORD-001', ['status' => 'shipped']);
$repo->delete('ORD-001');
```

## Eager Loading

Enable eager loading by defining `$relationConfig` using relation DTO classes:

```php
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\HasMany;

class PostRepository extends BaseRepository
{
    protected array $relationConfig = [];

    public UserRepository $userRepository;
    public CommentRepository $commentRepository;

    public function __construct(
        Connection $connection,
        UserRepository $userRepo,
        CommentRepository $commentRepo
    ) {
        $this->userRepository = $userRepo;
        $this->commentRepository = $commentRepo;
        $this->relationConfig = [
            'user' => new BelongsTo(
                repository: 'userRepository',
                foreignKey: 'user_id',
                setter: 'setUser',
            ),
            'comments' => new HasMany(
                repository: 'commentRepository',
                foreignKey: 'post_id',
                setter: 'setComments',
                orderBy: ['created_at' => 'ASC'],
            ),
        ];
        parent::__construct($connection, Post::class, 'posts');
    }
}
```

### Relation DTO Classes

| Class | Description |
|-------|-------------|
| `BelongsTo` | N:1 relation (model has foreign key) |
| `HasOne` | 1:1 relation (related model has foreign key, returns single object) |
| `HasMany` | 1:N relation (related model has foreign key, returns array) |
| `BelongsToMany` | N:M relation (via pivot table) |

### Relation Types

#### BelongsTo
The model has a foreign key pointing to the related model's primary key.
```php
use Solo\BaseRepository\Relation\BelongsTo;

// User belongs to Company (users.company_id -> companies.id)
'company' => new BelongsTo(
    repository: 'companyRepository',
    foreignKey: 'company_id',
    setter: 'setCompany',
),
```

#### HasOne
The related model has a foreign key pointing to this model's primary key. Returns a single object or null.
```php
use Solo\BaseRepository\Relation\HasOne;

// User has one Profile (profiles.user_id -> users.id)
'profile' => new HasOne(
    repository: 'profileRepository',
    foreignKey: 'user_id',
    setter: 'setProfile',
),
```

#### HasMany
The related model has a foreign key pointing to this model's primary key. Returns an array of objects.
```php
use Solo\BaseRepository\Relation\HasMany;

// Post has many Comments (comments.post_id -> posts.id)
'comments' => new HasMany(
    repository: 'commentRepository',
    foreignKey: 'post_id',
    setter: 'setComments',
    orderBy: ['created_at' => 'ASC'],
),
```

#### BelongsToMany
Many-to-many relation via pivot table.
```php
use Solo\BaseRepository\Relation\BelongsToMany;

// Article has many Tags via article_tag pivot table
'tags' => new BelongsToMany(
    repository: 'tagRepository',
    pivot: 'article_tag',
    foreignPivotKey: 'article_id',
    relatedPivotKey: 'tag_id',
    setter: 'setTags',
    orderBy: ['name' => 'ASC'],
),
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
use Solo\BaseRepository\Relation\BelongsTo;

class PostRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';
    protected array $relationConfig = [];

    public UserRepository $userRepository;

    public function __construct(Connection $connection, UserRepository $userRepo)
    {
        $this->userRepository = $userRepo;
        $this->relationConfig = [
            'user' => new BelongsTo(
                repository: 'userRepository',
                foreignKey: 'user_id',
                setter: 'setUser',
            ),
        ];
        parent::__construct($connection, Post::class, 'posts');
    }
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