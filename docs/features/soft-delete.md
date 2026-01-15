# Soft Delete

Soft delete allows you to "delete" records without actually removing them from the database. Instead, a timestamp is set in a designated column.

## Setup

Enable soft delete by setting the `$deletedAtColumn` property:

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

::: info Database Column
Ensure your table has the `deleted_at` column:

```sql
ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
```
:::

---

## Behavior Changes

When soft delete is enabled:

| Method | Without Soft Delete | With Soft Delete |
|--------|---------------------|------------------|
| `delete()` | Physical DELETE | Sets `deleted_at = NOW()` |
| `deleteBy()` | Physical DELETE | Sets `deleted_at = NOW()` |
| `find()` | Returns any record | Excludes deleted records |
| `findBy()` | Returns any record | Excludes deleted records |
| `findAll()` | Returns all | Excludes deleted records |
| `count()` | Counts all | Excludes deleted records |

---

## Basic Usage

```php
// Create a user
$user = $repo->create(['name' => 'John', 'email' => 'john@example.com']);

// Soft delete (sets deleted_at)
$repo->delete($user->id);

// User is now "invisible" to normal queries
$user = $repo->find($user->id); // Returns null!

// Restore the user
$repo->restore($user->id);

// User is visible again
$user = $repo->find($user->id); // Returns the user
```

---

## Querying Records

### Active Records Only (Default)

```php
// Only non-deleted records
$users = $repo->findAll();
$users = $repo->findBy(['status' => 'active']);
$count = $repo->count([]);
```

### Deleted Records Only

```php
// Only soft-deleted records
$deleted = $repo->findBy(['deleted_at' => ['!=' => null]]);
```

### All Records (Including Deleted)

Use the special `'*'` value:

```php
// Active + deleted
$all = $repo->findBy(['deleted_at' => '*']);

// With other criteria
$all = $repo->findBy([
    'status' => 'premium',
    'deleted_at' => '*'  // Include deleted premium users
]);
```

---

## Soft Delete Methods

### restore()

Restore a soft-deleted record by setting `deleted_at` to NULL.

```php
public function restore(int|string $id): int
```

```php
$repo->delete(1);     // Soft delete
$repo->restore(1);    // Restore

// Returns 0 if soft delete not enabled or record not found
```

### forceDelete()

Permanently delete a record, bypassing soft delete.

```php
public function forceDelete(int|string $id): int
```

```php
// Physical deletion (even with soft delete enabled)
$repo->forceDelete(1);
```

### forceDeleteBy()

Permanently delete multiple records matching criteria.

```php
public function forceDeleteBy(array $criteria): int
```

```php
// Purge all soft-deleted records older than 30 days
$purged = $repo->forceDeleteBy([
    'deleted_at' => ['<' => date('Y-m-d', strtotime('-30 days'))]
]);

echo "Purged {$purged} records";
```

---

## Practical Examples

### Trash / Recycle Bin

```php
class UserRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';

    // Get all trashed users
    public function findTrashed(): array
    {
        return $this->findBy(['deleted_at' => ['!=' => null]]);
    }

    // Empty trash (permanently delete)
    public function emptyTrash(int $daysOld = 30): int
    {
        return $this->forceDeleteBy([
            'deleted_at' => ['<' => date('Y-m-d H:i:s', strtotime("-{$daysOld} days"))]
        ]);
    }
}

// Usage
$trashedUsers = $userRepo->findTrashed();
$purgedCount = $userRepo->emptyTrash(30);
```

### Admin Panel with Restore

```php
// List all users for admin (including deleted)
$allUsers = $repo->findBy(['deleted_at' => '*'], ['created_at' => 'DESC']);

// Restore user
$repo->restore($userId);

// Permanently delete
$repo->forceDelete($userId);
```

### GDPR Compliance

```php
// User requests account deletion
public function deleteUserAccount(int $userId): void
{
    // Soft delete first (grace period)
    $this->userRepo->delete($userId);
    
    // Schedule permanent deletion after 30 days
    $this->scheduler->schedule(
        new PermanentDeleteJob($userId),
        '+30 days'
    );
}

// After grace period
public function permanentlyDeleteUser(int $userId): void
{
    // Delete related data
    $this->orderRepo->forceDeleteBy(['user_id' => $userId]);
    $this->commentRepo->forceDeleteBy(['user_id' => $userId]);
    
    // Permanently delete user
    $this->userRepo->forceDelete($userId);
}
```

---

## Combining with Eager Loading

Soft delete works seamlessly with eager loading:

```php
// Active posts with their users (soft delete on both)
$posts = $postRepo->with(['user'])->findAll();

// Include deleted posts
$allPosts = $postRepo->with(['user'])->findBy(['deleted_at' => '*']);
```

::: warning Related Records
Eager loading respects soft delete on the related repository. If both `PostRepository` and `UserRepository` have soft delete enabled, deleted users won't be loaded.
:::

---

## Custom Column Name

You can use any column name:

```php
class ArticleRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'archived_at';
}
```

Just ensure the column exists in your table and your model handles it correctly.
