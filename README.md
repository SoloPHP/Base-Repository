# Solo Base Repository

Lightweight PHP repository pattern with built-in soft delete, eager loading, and rich criteria syntax.

[![Latest Version](https://img.shields.io/packagist/v/solophp/base-repository.svg)](https://packagist.org/packages/solophp/base-repository)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/base-repository.svg)](https://packagist.org/packages/solophp/base-repository)
[![License](https://img.shields.io/packagist/l/solophp/base-repository.svg)](LICENSE)

## Features

- Soft delete with restore/force delete
- Eager loading (BelongsTo, HasOne, HasMany, BelongsToMany)
- Rich criteria syntax: operators, BETWEEN, OR/AND groups, and correlated EXISTS via relation dot-notation
- Built-in aggregations (count, sum, avg, min, max)
- Translation via `withLocale()` with optional fallback locale — auto LEFT JOIN, propagates into relations
- Transaction helpers with row locking (`SELECT ... FOR UPDATE`) and cross-process advisory locks (`withLock()`) for idempotency
- Custom IDs (UUID, ULID, prefixed) via `$autoIncrement = false`

## Installation
```bash
composer require solophp/base-repository
```

**Requirements:** PHP 8.3+, Doctrine DBAL ^4.3

**Database:** any Doctrine DBAL platform. Locking is platform-specific:
`lockForUpdate()` — MySQL/MariaDB, PostgreSQL, Oracle; `withLock()` advisory locking —
MySQL/MariaDB (`GET_LOCK`) and PostgreSQL (`pg_advisory_lock`). Other platforms throw on these calls.

## Quick Example
```php
class UserRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, User::class, 'users');
    }
}

// Usage
$users = $repo->findBy(['status' => 'active'], ['created_at' => 'DESC'], 20, 1);
$repo->delete($id);      // Soft delete
$repo->restore($id);     // Restore
```
## Documentation

📖 **[Full Documentation](https://solophp.github.io/Base-Repository/)**

- [Installation](https://solophp.github.io/Base-Repository/guide/installation)
- [Quick Start](https://solophp.github.io/Base-Repository/guide/quick-start)
- [Criteria Syntax](https://solophp.github.io/Base-Repository/features/criteria)
- [Soft Delete](https://solophp.github.io/Base-Repository/features/soft-delete)
- [Eager Loading](https://solophp.github.io/Base-Repository/features/eager-loading)
- [Translations](https://solophp.github.io/Base-Repository/features/translations)
- [API Reference](https://solophp.github.io/Base-Repository/methods/retrieval)

## License

MIT