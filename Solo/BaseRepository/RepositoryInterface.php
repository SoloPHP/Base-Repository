<?php

namespace Solo\BaseRepository;

/**
 * Interface RepositoryInterface
 *
 * Defines a common interface for repository classes.
 * Provides methods for retrieving, persisting, and managing database records as arrays.
 *
 * @package Solo\BaseRepository
 */
interface RepositoryInterface
{
    /**
     * Retrieve all records from the table.
     *
     * @return array<int, array<string, mixed>> List of records as associative arrays.
     */
    public function findAll(): array;

    /**
     * Find a record by its primary ID.
     *
     * @param int|string $id The primary key of the record.
     * @return array<string, mixed>|null Returns the record as an associative array, or null if not found.
     */
    public function findById(int|string $id): ?array;

    /**
     * Find multiple records by their IDs.
     *
     * @param array<int|string> $ids List of primary keys.
     * @return array<int, array<string, mixed>> List of records as associative arrays.
     */
    public function findByIds(array $ids): array;

    /**
     * Find records based on specified criteria.
     *
     * @param array<string, mixed> $criteria An associative array of column => value pairs.
     * @return array<int, array<string, mixed>> List of matching records as associative arrays.
     */
    public function findBy(array $criteria): array;

    /**
     * Find a single record based on specified criteria.
     *
     * @param array<string, mixed> $criteria An associative array of column => value pairs.
     * @return array<string, mixed>|null Returns the record as an associative array, or null if not found.
     */
    public function findOneBy(array $criteria): ?array;

    /**
     * Find records by a pattern in one or more fields.
     *
     * @param string|array<string> $fields The field name or array of field names to search in.
     * @param string $pattern The pattern to match.
     * @return array<int, array<string, mixed>> List of matching records as associative arrays.
     */
    public function findByLike(string|array $fields, string $pattern): array;

    /**
     * Count records based on criteria.
     *
     * @param array<string, mixed> $criteria An associative array of column => value pairs.
     * @return int The count of records that match the criteria.
     */
    public function countBy(array $criteria = []): int;

    /**
     * Paginate results.
     *
     * @param int $page The page number (starting from 1).
     * @param int $limit The number of records per page.
     * @return array<int, array<string, mixed>> Paginated records as associative arrays.
     */
    public function paginate(int $page = 1, int $limit = 10): array;

    /**
     * Insert a new record into the table.
     *
     * @param array<string, mixed> $data The record data to insert.
     * @return bool True on success, false otherwise.
     */
    public function create(array $data): bool;

    /**
     * Bulk insert multiple records into the table.
     *
     * @param array<int, array<string, mixed>> $records An array of associative arrays representing records.
     * @return bool True on success, false otherwise.
     */
    public function bulkInsert(array $records): bool;

    /**
     * Update a record by its ID.
     *
     * @param int|string $id The identifier of the record to update.
     * @param array<string, mixed> $data The record data to update.
     * @return bool True on success, false otherwise.
     */
    public function update(int|string $id, array $data): bool;

    /**
     * Delete a record by its ID.
     *
     * @param int|string $id The identifier of the record to delete.
     * @return bool True on success, false otherwise.
     */
    public function delete(int|string $id): bool;

    /**
     * Count the total number of records.
     *
     * @return int The total count of records in the table.
     */
    public function count(): int;

    /**
     * Check if a record exists based on criteria.
     *
     * @param array<string, mixed> $criteria An associative array of column => value pairs.
     * @return bool True if at least one matching record exists, false otherwise.
     */
    public function exists(array $criteria): bool;

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commit the current database transaction.
     */
    public function commit(): void;

    /**
     * Rollback the current database transaction.
     */
    public function rollback(): void;
}