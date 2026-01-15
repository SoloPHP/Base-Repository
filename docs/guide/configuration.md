# Configuration

## Constructor Parameters

```php
__construct(
    protected Connection $connection,
    string $modelClass,
    string $table,
    ?string $tableAlias = null,
    string $mapperMethod = 'fromArray'
)
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$connection` | `Connection` | Doctrine DBAL connection instance |
| `$modelClass` | `string` | Fully qualified class name of your model |
| `$table` | `string` | Database table name |
| `$tableAlias` | `?string` | Optional table alias (defaults to first letter) |
| `$mapperMethod` | `string` | Static method name for array-to-model mapping |

## Configurable Properties

Override these properties in your repository class:

```php
class UserRepository extends BaseRepository
{
    // Change primary key column (default: 'id')
    protected string $primaryKey = 'user_id';
    
    // Enable soft delete
    protected ?string $deletedAtColumn = 'deleted_at';
    
    // Configure relations for eager loading
    protected array $relationConfig = [];
    
    // Custom table alias
    protected ?string $tableAlias = 'u';
}
```

### Properties Reference

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$primaryKey` | `string` | `'id'` | Primary key column name |
| `$tableAlias` | `?string` | `null` | Table alias for queries (defaults to first letter of table name) |
| `$deletedAtColumn` | `?string` | `null` | Column for soft delete timestamp. Set to enable soft delete |
| `$relationConfig` | `array` | `[]` | Relation definitions for eager loading |

## Feature Auto-Detection

Features are automatically enabled based on your configuration:

- **Soft Delete** — Enabled when `$deletedAtColumn` is set
- **Eager Loading** — Enabled when `$relationConfig` is non-empty
- **Custom IDs** — Auto-detected when you provide ID in `create()` data

## Minimal Repository

```php
class LogRepository extends BaseRepository
{
    public function __construct(Connection $connection)
    {
        parent::__construct($connection, Log::class, 'logs');
    }
}
```

## Full-Featured Repository

```php
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\HasMany;

class PostRepository extends BaseRepository
{
    protected string $primaryKey = 'id';
    protected ?string $deletedAtColumn = 'deleted_at';
    protected array $relationConfig = [];

    public function __construct(
        Connection $connection,
        public UserRepository $userRepository,
        public CommentRepository $commentRepository
    ) {
        $this->relationConfig = [
            'author' => new BelongsTo(
                repository: 'userRepository',
                foreignKey: 'author_id',
                setter: 'setAuthor',
            ),
            'comments' => new HasMany(
                repository: 'commentRepository',
                foreignKey: 'post_id',
                setter: 'setComments',
                orderBy: ['created_at' => 'DESC'],
            ),
        ];

        parent::__construct($connection, Post::class, 'posts');
    }
}
```

## Custom Mapper Method

If your model uses a different method name:

```php
class User
{
    public static function createFromDatabase(array $data): self
    {
        // ...
    }
}

class UserRepository extends BaseRepository
{
    public function __construct(Connection $connection)
    {
        parent::__construct(
            $connection,
            User::class,
            'users',
            null,
            'createFromDatabase'  // Custom mapper method
        );
    }
}
```
