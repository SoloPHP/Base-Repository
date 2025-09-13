<?php

declare(strict_types=1);

namespace Solo\BaseRepository;

use Doctrine\DBAL\Connection;

/**
 * @template TModel of object
 */
interface RepositoryInterface
{
    /**
     * @return TModel|null
     */
    public function getById(int|string $id): ?object;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'>|null $orderBy
     * @return TModel|null
     */
    public function getFirstBy(array $criteria, ?array $orderBy = null): ?object;

    /**
     * @return list<TModel>
     */
    public function getAll(): array;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'>|null $orderBy
     * @param int|null $perPage
     * @param int|null $page
     * @return list<TModel>
     */
    public function getBy(array $criteria, ?array $orderBy = null, ?int $perPage = null, ?int $page = null): array;


    /**
     * @param array<string, mixed> $criteria
     */
    public function existsBy(array $criteria): bool;

    /**
     * @param array<string, mixed> $criteria
     */
    public function countBy(array $criteria): int;


    /**
     * @param array<string, mixed> $data
     * @return int|string|null
     */
    public function insert(array $data): int|string|null;

    /**
     * @param array<string, mixed> $data
     * @return TModel
     * @throws \RuntimeException
     */
    public function insertAndGet(array $data): object;

    /**
     * @param list<array<string, mixed>> $records
     * @return int
     */
    public function insertBatch(array $records): int;

    /**
     * @param array<string, mixed> $data
     * @return int
     */
    public function update(int|string $id, array $data): int;

    /**
     * @param array<string, mixed> $data
     * @return TModel
     * @throws \RuntimeException
     */
    public function updateAndGet(int|string $id, array $data): object;

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
    public function deleteById(int|string $id): int;

    /**
     * @param array<string, mixed> $criteria
     * @return int
     */
    public function deleteBy(array $criteria): int;

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
     * @param callable(self): TReturn $callback
     * @return TReturn
     */
    /**
     * @template TReturn
     * @param callable(RepositoryInterface<TModel>): TReturn $callback
     * @return TReturn
     */
    public function withTransaction(callable $callback): mixed;
}
