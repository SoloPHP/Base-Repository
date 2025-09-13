## Solo Base Repository

[![Packagist Version](https://img.shields.io/packagist/v/solophp/base-repository.svg?style=flat-square)](https://packagist.org/packages/solophp/base-repository)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/base-repository.svg?style=flat-square)](https://packagist.org/packages/solophp/base-repository)
[![License](https://img.shields.io/packagist/l/solophp/base-repository.svg?style=flat-square)](LICENSE)

Lightweight base repository for Doctrine DBAL.
- **`RepositoryInterface<TModel>`**: generic contract for CRUD, aggregates, pagination, and transactions.
- **`BaseRepository<TModel>`**: ready-to-extend base with criteria parsing, sorting, mapping.
- **`BaseSoftDeletableRepository<TModel>`**: optional base class for soft delete functionality with fluent API.

### Installation
```bash
composer require solophp/base-repository
```

### Requirements
- PHP 8.2+
- Doctrine DBAL (`doctrine/dbal` ^4.3)

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
| `$deletedAtColumn` | string | `'deleted_at'` | Soft-delete timestamp column (BaseSoftDeletableRepository) |

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
| `getById(int\|string $id): ?TModel` | Get model by primary key |
| `getFirstBy(array $criteria, ?array $orderBy = null): ?TModel` | First by criteria and sort |
| `getAll(): list<TModel>` | All rows |
| `getBy(array $criteria, ?array $orderBy = null, ?int $perPage = null, ?int $page = null): list<TModel>` | Filtered list, optional pagination |

### Mutation Methods

| Method | Description |
|---|---|
| `insert(array $data): int\|string\|null` | Insert and return ID (int\|string\|null) |
| `insertAndGet(array $data): TModel` | Insert and return model object |
| `insertBatch(list<array<string,mixed>> $records): int` | Bulk insert, returns affected rows |
| `update(int\|string $id, array $data): int` | Update by ID |
| `updateAndGet(int\|string $id, array $data): TModel` | Update and return model object |
| `updateBy(array $criteria, array $data): int` | Update by criteria |
| `deleteById(int\|string $id): int` | Soft or hard delete by ID |
| `deleteBy(array $criteria): int` | Soft or hard delete by criteria |
| `forceDeleteById(int\|string $id): int` | Hard delete by ID |
| `forceDeleteBy(array $criteria): int` | Hard delete by criteria |

### Existence and Aggregates

| Method | Description |
|---|---|
| `existsBy(array $criteria): bool` | Check existence |
| `countBy(array $criteria): int` | Count rows |


## BaseSoftDeletableRepository

Optional base class for repositories requiring soft delete functionality. When extended, it modifies default behavior and adds soft delete methods.

### Usage
```php
use Solo\BaseRepository\BaseSoftDeletableRepository;

final class UserRepository extends BaseSoftDeletableRepository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, User::class, 'users');
    }
}
```

### Fluent API

| Method | Description |
|---|---|
| `withTrashed(): self` | Include soft-deleted records in next query |
| `restoreById(int\|string $id): int` | Restore soft-deleted record |
| `forceDeleteById(int\|string $id): int` | Hard delete bypassing soft delete |
| `forceDeleteBy(array $criteria): int` | Hard delete by criteria bypassing soft delete |

### Examples
```php
// Safe behavior by default (only active records)
$users = $repo->getAll();                    // Only active records
$repo->deleteById(1);                        // Soft delete (sets deleted_at)
$repo->deleteBy(['status' => 'inactive']);   // Soft delete (sets deleted_at)

// Include soft-deleted records
$allUsers = $repo->withTrashed()->getAll();      // All including soft-deleted
$allInactive = $repo->withTrashed()->getBy(['status' => 'inactive']); // All inactive including soft-deleted

// Hard delete (physical removal)
$repo->forceDeleteById(1);                   // Physical deletion
$repo->forceDeleteBy(['status' => 'test']);  // Physical deletion

// Restore soft-deleted records
$repo->restoreById(1);                       // Sets deleted_at = NULL

// API filtering still works
$deleted = $repo->getBy(['deleted' => 'only']);     // Only soft-deleted
$all = $repo->getBy(['deleted' => 'with']);         // All including soft-deleted
$active = $repo->getBy(['deleted' => 'without']);   // Only active (default behavior)
```

### Transactions

| Method | Description |
|---|---|
| `beginTransaction(): bool` | Begin transaction |
| `commit(): bool` | Commit |
| `rollBack(): bool` | Rollback |
| `inTransaction(): bool` | Transaction state |
| `withTransaction(callable $cb): mixed` | Execute callback in transaction |

### Example

```php
use Solo\BaseRepository\BaseRepository;
use Doctrine\DBAL\Connection;

final class UserRepository extends BaseRepository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, User::class, 'users');
    }
}

// Usage

// 1) Basic filtering and sorting with pagination
$users = $repo->getBy(
    ['status' => 'active', 'age' => ['>', 18]],
    ['created_at' => 'DESC'],
    20,  // perPage
    1    // page
);

// 2) Criteria variants
$byNull = $repo->getBy(['deleted_at' => null]);
$byIn = $repo->getBy(['id' => [1, 5, 7]]);
$byOp = $repo->getBy(['score' => ['>=', 90]]);

// 3) Search queries (LIKE)
$filtered = $repo->getBy([
    'search' => ['name' => 'john', 'email' => 'example.com']
]);

// 4) Deleted filter (soft-deletes)
$withDeleted = $repo->getBy(['deleted' => 'with']);
$onlyDeleted = $repo->getBy(['deleted' => 'only']);

// 5) Pagination implementation in controller
$criteria = ['status' => 'active'];
$orderBy = ['id' => 'ASC'];
$perPage = 10; $page = 2;

$items = $repo->getBy($criteria, $orderBy, $perPage, $page);
$total = $repo->countBy($criteria);
// Build paginated response: ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage]


// 7) Existence and counts
$exists = $repo->existsBy(['email' => 'john@example.com']);
$totalActive = $repo->countBy(['status' => 'active']);

// 8) Inserts and updates
$newId = $repo->insert(['name' => 'John', 'email' => 'john@example.com']);
$affected = $repo->insertBatch([
    ['name' => 'Jane', 'email' => 'jane@example.com'],
    ['name' => 'Bob',  'email' => 'bob@example.com'],
]);
$repo->update($newId, ['name' => 'John Doe']);
$repo->updateBy(['status' => 'pending'], ['status' => 'active']);

// 9) Basic delete operations
$repo->deleteById($newId);               // hard delete (or soft delete if using BaseSoftDeletableRepository)

// 10) Transactions
$repo->withTransaction(function (UserRepository $r) {
    $id = $r->insert(['name' => 'Temp', 'email' => 'temp@example.com']);
    $r->update($id, ['name' => 'Temp Updated']);
    // throw new RuntimeException('rollback'); // Uncomment to rollback
});
```

### Notes
- Validate fields in `criteria`/`orderBy` against a whitelist if user-provided.
- Soft-delete timestamp uses `{NOW()}` (raw). Adapt if you need database-agnostic timestamps.



### Extending repositories

You can extend `BaseRepository` to add domain-specific behavior, scopes, or stricter validation. Below are common patterns.

1) Add domain-specific methods using `table()` and builder chaining:

```php
use Solo\BaseRepository\BaseRepository;

final class UserRepository extends BaseRepository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, 'users', User::class, 'fromArray');
    }

    // Use table() then chain builder methods and map results
    /** @return list<User> */
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

    // With JOIN and custom select
    /** @return list<User> */
    public function withProfiles(array $userIds): array
    {
        $qb = $this->table()
            ->select('users.*', 'profiles.score as profile_score')
            ->join('users', 'profiles', 'profiles', 'profiles.user_id = users.id')
            ->andWhere($qb->expr()->in('users.id', ':userIds'))
            ->setParameter('userIds', $userIds, \Doctrine\DBAL\ArrayParameterType::STRING);
        
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(fn(array $r) => $this->mapRowToModel($r), $rows);
    }

    // Convenience wrappers for readability
    public function findActiveByEmail(string $email): ?User
    {
        return $this->getFirstBy(['status' => 'active', 'email' => $email]);
    }
}
```

2) Extend criteria syntax (e.g., LIKE, BETWEEN) by overriding `applyCriteria`:

```php
use Doctrine\DBAL\Query\QueryBuilder;

protected function applyCriteria(QueryBuilder $qb, array $criteria): QueryBuilder
{
    $handled = [];

    foreach ($criteria as $field => $value) {
        // LIKE: ['name' => ['like', '%john%']]
        if (is_array($value) && ($value[0] ?? null) === 'like') {
            $qb->andWhere("{$field} LIKE :{$field}")
               ->setParameter($field, $value[1] ?? '');
            $handled[$field] = true;
            continue;
        }

        // BETWEEN: ['created_at' => ['between', ['2024-01-01','2024-12-31']]]
        if (is_array($value) && ($value[0] ?? null) === 'between' && is_array($value[1] ?? null)) {
            [$from, $to] = $value[1];
            $qb->andWhere("{$field} BETWEEN :{$field}_from AND :{$field}_to")
               ->setParameter($field . '_from', $from)
               ->setParameter($field . '_to', $to);
            $handled[$field] = true;
            continue;
        }
    }

    // Delegate remaining keys to the base implementation
    $remaining = array_diff_key($criteria, $handled);
    return parent::applyCriteria($qb, $remaining);
}
```

3) Whitelist sortable fields by overriding sorting:

```php
protected array $orderWhitelist = ['id', 'created_at', 'name'];

protected function applyOrderBy(QueryBuilder $qb, array $orderBy): void
{
    foreach ($orderBy as $field => $direction) {
        if (!in_array($field, $this->orderWhitelist, true)) {
            continue; // ignore non-whitelisted fields
        }
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $qb->orderBy($field, $dir);
    }
}
```

4) Harden identifier safety or add field whitelists by overriding `assertSafeIdentifier` or validating inputs before calling aggregates/order-by.


### License

This library is released under the MIT License. See the `LICENSE` file for details.

