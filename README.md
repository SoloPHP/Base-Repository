# Solo Base Repository

Lightweight PHP repository pattern with built-in soft delete, eager loading, and rich criteria syntax.

[![Latest Version](https://img.shields.io/packagist/v/solophp/base-repository.svg)](https://packagist.org/packages/solophp/base-repository)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/base-repository.svg)](https://packagist.org/packages/solophp/base-repository)
[![License](https://img.shields.io/packagist/l/solophp/base-repository.svg)](LICENSE)

## Features

- 🗑️ Soft delete with restore/force delete
- 🔗 Eager loading (BelongsTo, HasOne, HasMany, BelongsToMany)
- 🔍 Rich criteria syntax with operators and relation filters
- 📊 Built-in aggregations (count, sum, avg, min, max)
- 🔐 Transaction helpers
- 🆔 Auto-detect custom IDs (UUID, prefixed)

## Installation
```bash
composer require solophp/base-repository
```

**Requirements:** PHP 8.3+, Doctrine DBAL ^4.3

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

📖 **[Full Documentation](https://solophp.github.io/base-repository/)**

- [Installation](https://solophp.github.io/base-repository/guide/installation)
- [Quick Start](https://solophp.github.io/base-repository/guide/quick-start)
- [Criteria Syntax](https://solophp.github.io/base-repository/features/criteria)
- [Soft Delete](https://solophp.github.io/base-repository/features/soft-delete)
- [Eager Loading](https://solophp.github.io/base-repository/features/eager-loading)
- [API Reference](https://solophp.github.io/base-repository/methods/retrieval)

## License

MIT