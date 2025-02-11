# Solo BaseRepository 📦

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/solophp/base-repository)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)

Solo BaseRepository provides a foundational abstract class for implementing the Repository pattern in PHP applications. It offers a standardized set of database operations through a query builder, allowing you to create specific repositories with custom data access methods while maintaining consistent base functionality.

## ✨ Features

- Abstract base class with essential repository operations
- Built-in integration with query builder for flexible SQL queries
- Standard CRUD operations with array data structures
- Pagination, filtering, and search capabilities
- Transaction management support
- Extensible design for custom repository implementations

## 📥 Installation

```sh
composer require solophp/base-repository
```

## 🔗 Dependencies

This package requires the following dependencies:

- `solophp/database` for query execution
- `solophp/query-builder` for building SQL queries

## 🚀 Usage

### Creating a Repository

Extend `BaseRepository` to create a repository for your specific table:

```php
class UserRepository extends BaseRepository {
    public function __construct(Database $db) {
        parent::__construct($db, 'users');
    }

    // Add custom methods specific to user data access
    public function findActiveUsers(): array {
        return $this->queryBuilder
            ->select()
            ->where('status', '=', 'active')
            ->get();
    }
}
```

### Available Base Methods

- `findAll()`: Retrieve all records.
- `findById(int|string $id)`: Retrieve a record by its ID.
- `findByIds(array $ids)`: Retrieve multiple records by IDs.
- `findBy(array $criteria)`: Find records matching criteria.
- `findOneBy(array $criteria)`: Find a single record matching criteria.
- `findByLike(string|array $fields, string $pattern)`: Find records by pattern in specified fields.
- `countBy(array $criteria = [])`: Count records matching criteria.
- `paginate(int $page = 1, int $limit = 10)`: Retrieve paginated records.
- `create(array $data)`: Insert a new record.
- `bulkInsert(array $records)`: Bulk insert multiple records.
- `update(int|string $id, array $data)`: Update a record by ID.
- `delete(int|string $id)`: Delete a record by ID.
- `count()`: Count total records in the table.
- `exists(array $criteria)`: Check if a record exists.
- `beginTransaction()`, `commit()`, `rollback()`: Transaction management.

### Using Repository Methods

Basic CRUD operations:

```php
$userRepository = new UserRepository($db);

// Find all users
$users = $userRepository->findAll();

// Find by ID
$user = $userRepository->findById(1);

// Find with criteria
$activeUsers = $userRepository->findBy(['status' => 'active']);

// Create new user
$userRepository->create([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

// Bulk insert users
$userRepository->bulkInsert([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com']
]);

// Update user
$userRepository->update(1, ['email' => 'newemail@example.com']);

// Delete user
$userRepository->delete(1);
```

### Using Transactions

```php
$userRepository->beginTransaction();
try {
    $userRepository->create(['name' => 'Test User']);
    $userRepository->commit();
} catch (Exception $e) {
    $userRepository->rollback();
}
```

## ⚙️ Requirements

- PHP 8.2+

## 🤝 Issues & Contributions

Feel free to open an issue or submit a pull request!

## 📄 License

This project is licensed under the [MIT License](LICENSE).