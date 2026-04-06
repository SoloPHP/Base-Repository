<?php

declare(strict_types=1);

namespace Solo\BaseRepository;

/**
 * @template TModel of object
 */
interface RepositoryInterface
{
    /**
     * @return TModel|null
     */
    public function find(int|string $id): ?object;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'>|null $orderBy
     * @return TModel|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object;

    /**
     * @return list<TModel>
     */
    public function findAll(): array;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'>|null $orderBy
     * @param int|null $perPage
     * @param int|null $page
     * @return list<TModel>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $perPage = null, ?int $page = null): array;


    /**
     * @param array<string, mixed> $criteria
     */
    public function exists(array $criteria): bool;

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria): int;

    /**
     * @param string $column
     * @param array<string, mixed> $criteria
     * @return int|float
     */
    public function sum(string $column, array $criteria = []): int|float;

    /**
     * @param string $column
     * @param array<string, mixed> $criteria
     * @return int|float
     */
    public function avg(string $column, array $criteria = []): int|float;

    /**
     * @param string $column
     * @param array<string, mixed> $criteria
     * @return mixed
     */
    public function min(string $column, array $criteria = []): mixed;

    /**
     * @param string $column
     * @param array<string, mixed> $criteria
     * @return mixed
     */
    public function max(string $column, array $criteria = []): mixed;


    /**
     * Create a record and return the hydrated model
     * @param array<string, mixed> $data
     * @return TModel
     * @throws \RuntimeException
     */
    public function create(array $data): object;

    /**
     * Insert a record without returning the model
     * @param array<string, mixed> $data
     * @return int Affected rows
     */
    public function insert(array $data): int;

    /**
     * @param list<array<string, mixed>> $records
     * @return int
     */
    public function insertMany(array $records): int;

    /**
     * Update a record by ID and return the hydrated model
     * @param array<string, mixed> $data
     * @return TModel
     * @throws \RuntimeException
     */
    public function update(int|string $id, array $data): object;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $data
     * @return int
     */
    public function updateBy(array $criteria, array $data): int;

    /**
     * @param int|string $id
     * @return int
     */
    public function delete(int|string $id): int;

    /**
     * @param array<string, mixed> $criteria
     * @return int
     */
    public function deleteBy(array $criteria): int;


    /**
     * Force delete (permanent) bypassing soft delete
     * @param int|string $id
     * @return int
     */
    public function forceDelete(int|string $id): int;

    /**
     * Force delete by criteria (permanent) bypassing soft delete
     * @param array<string, mixed> $criteria
     * @return int
     */
    public function forceDeleteBy(array $criteria): int;

    /**
     * Restore soft deleted record (if soft delete is enabled)
     * @param int|string $id
     * @return int
     */
    public function restore(int|string $id): int;

    /**
     * Specify relations to eager load (if eager loading is enabled)
     * @param array $relations
     * @return static
     */
    public function with(array $relations): static;

    /**
     * Set locale for translation JOIN.
     * The next query will LEFT JOIN the translation table and include translated fields.
     *
     * @param string $locale
     * @return static
     */
    public function withLocale(string $locale): static;

    /**
     * Clear locale (disable translation JOIN)
     *
     * @return static
     */
    public function withoutLocale(): static;

    /**
     * Attach related IDs to a BelongsToMany relation via pivot table.
     *
     * @param string $relation Relation name from $relationConfig
     * @param int|string $id Parent model ID
     * @param list<int|string> $relatedIds Related model IDs to attach
     */
    public function attach(string $relation, int|string $id, array $relatedIds): void;

    /**
     * Detach related IDs from a BelongsToMany relation via pivot table.
     * If $relatedIds is empty, detaches all.
     *
     * @param string $relation Relation name from $relationConfig
     * @param int|string $id Parent model ID
     * @param list<int|string> $relatedIds Related model IDs to detach (empty = all)
     */
    public function detach(string $relation, int|string $id, array $relatedIds = []): void;

    /**
     * Sync a BelongsToMany relation: keep only the given related IDs.
     *
     * @param string $relation Relation name from $relationConfig
     * @param int|string $id Parent model ID
     * @param list<int|string> $relatedIds Related model IDs to sync
     */
    public function sync(string $relation, int|string $id, array $relatedIds): void;

    /**
     * Lock row(s) by primary key with SELECT ... FOR UPDATE.
     * Must be called inside a transaction.
     *
     * @param int|string|array<int|string> $id Single ID or array of IDs
     */
    public function lockForUpdate(int|string|array $id): void;

    /**
     * @return bool
     */
    public function beginTransaction(): bool;

    /**
     * @return bool
     */
    public function commit(): bool;

    /**
     * @return bool
     */
    public function inTransaction(): bool;

    /**
     * @return bool
     */
    public function rollBack(): bool;

    /**
     * @template TReturn
     * @param callable(RepositoryInterface<TModel>): TReturn $callback
     * @return TReturn
     */
    public function withTransaction(callable $callback): mixed;
}
