<?php

declare(strict_types=1);

namespace Solo\BaseRepository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Solo\BaseRepository\RepositoryInterface;
use Solo\BaseRepository\Internal\ModelMapper;
use Solo\BaseRepository\Internal\QueryFactory;
use Solo\BaseRepository\Internal\CriteriaBuilder;
use Solo\BaseRepository\Internal\SoftDeleteService;
use Solo\BaseRepository\Internal\EagerLoadingService;

/**
 * @template TModel of object
 * @implements RepositoryInterface<TModel>
 */
abstract class BaseRepository implements RepositoryInterface
{
    protected string $primaryKey = 'id';
    protected CriteriaBuilder $criteriaBuilder;
    protected ModelMapper $modelMapper;
    protected QueryFactory $queryFactory;

    protected ?SoftDeleteService $softDeleteService = null;
    protected ?EagerLoadingService $eagerLoadingService = null;

    // Configuration properties - override in child classes
    protected ?string $deletedAtColumn = null;
    protected array $relationConfig = [];

    /**
     * @param Connection $connection
     * @param class-string<TModel> $modelClass
     * @param non-empty-string $table
     * @param non-empty-string|null $tableAlias
     * @param non-empty-string $mapperMethod
     */
    public function __construct(
        protected Connection $connection,
        /** @var class-string<TModel> */
        protected string $modelClass,
        protected string $table,
        protected ?string $tableAlias = null,
        protected string $mapperMethod = 'fromArray'
    ) {
        $this->criteriaBuilder = new CriteriaBuilder($this->getTableAlias(), $this->getDeletedAtColumn());
        $this->modelMapper = new ModelMapper($this->modelClass, $this->mapperMethod);
        $this->queryFactory = new QueryFactory($this->connection, $this->table, $this->getTableAlias());
        $this->initializeServices();
    }

    /**
     * Initialize services based on configuration
     */
    private function initializeServices(): void
    {
        // Initialize soft delete service if column is defined
        if ($this->deletedAtColumn !== null) {
            $this->softDeleteService = new SoftDeleteService($this->deletedAtColumn);
        }

        // Initialize eager loading service if relations are configured
        if (!empty($this->relationConfig)) {
            $this->eagerLoadingService = new EagerLoadingService();
        }
    }

    /**
     * @return QueryBuilder
     */
    protected function queryBuilder(): QueryBuilder
    {
        return $this->queryFactory->builder();
    }

    protected function table(): QueryBuilder
    {
        return $this->queryFactory->tableSelectAll();
    }

    /**
     * @return non-empty-string
     */
    private function getTableAlias(): string
    {
        return $this->tableAlias ?: substr($this->table, 0, 1);
    }

    /**
     * @return non-empty-string
     */
    protected function getDeletedAtColumn(): string
    {
        return $this->deletedAtColumn ?? 'deleted_at';
    }

    /**
     * @return TModel|null
     */
    public function find(int|string $id): ?object
    {
        return $this->findOneBy([$this->primaryKey => $id]);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'>|null $orderBy
     * @return TModel|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        // Apply soft delete logic if enabled
        if ($this->softDeleteService) {
            $criteria = $this->softDeleteService->applyCriteria($criteria);
        }

        $qb = $this->applyCriteria($this->table(), $criteria);
        if ($orderBy) {
            $this->applyOrderBy($qb, $orderBy);
        }
        $row = $qb->executeQuery()->fetchAssociative();

        if (!$row) {
            return null;
        }

        $item = $this->mapRowToModel($row);

        // Apply eager loading if enabled
        if ($this->eagerLoadingService && $this->eagerLoadingService->hasRelations()) {
            $items = $this->eagerLoadingService->loadRelations([$item], [$this, 'loadEagerRelations']);
            $this->eagerLoadingService->reset();
            return $items[0] ?? null;
        }

        return $item;
    }

    /**
     * @return list<TModel>
     */
    public function findAll(): array
    {
        // Use findBy with soft delete logic if enabled
        if ($this->softDeleteService) {
            return $this->findBy([]);
        }

        $rows = $this->table()->executeQuery()->fetchAllAssociative();
        $items = array_map(fn(array $r) => $this->mapRowToModel($r), $rows);

        // Apply eager loading if enabled
        if ($this->eagerLoadingService && $this->eagerLoadingService->hasRelations()) {
            $items = $this->eagerLoadingService->loadRelations($items, [$this, 'loadEagerRelations']);
            $this->eagerLoadingService->reset();
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'>|null $orderBy
     * @return list<TModel>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $perPage = null, ?int $page = null): array
    {
        // Apply soft delete logic if enabled
        if ($this->softDeleteService) {
            $criteria = $this->softDeleteService->applyCriteria($criteria);
        }

        $qb = $this->applyCriteria($this->table(), $criteria);
        if ($orderBy) {
            $this->applyOrderBy($qb, $orderBy);
        }
        if ($perPage !== null) {
            $offset = (($page ?? 1) - 1) * $perPage;
            $qb->setFirstResult($offset)->setMaxResults($perPage);
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $items = array_map(fn(array $r) => $this->mapRowToModel($r), $rows);

        // Apply eager loading if enabled
        if ($this->eagerLoadingService && $this->eagerLoadingService->hasRelations()) {
            $items = $this->eagerLoadingService->loadRelations($items, [$this, 'loadEagerRelations']);
            $this->eagerLoadingService->reset();
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    public function insertMany(array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $result = $this->withTransaction(function () use ($records) {
            $chunks = array_chunk($records, 500);
            $affected = 0;

            foreach ($chunks as $chunk) {
                foreach ($chunk as $record) {
                    $qb = $this->connection->createQueryBuilder()
                        ->insert($this->table);

                    foreach ($record as $column => $value) {
                        $qb->setValue($column, ':' . $column)
                           ->setParameter($column, $value);
                    }

                    $affected += $qb->executeStatement();
                }
            }

            return $affected;
        });
        return (int) $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return TModel
     */
    public function create(array $data): object
    {
        $qb = $this->queryFactory->insertBuilder();

        foreach ($data as $column => $value) {
            $qb->setValue($column, ':' . $column)
               ->setParameter($column, $value);
        }

        $qb->executeStatement();
        $id = $this->connection->lastInsertId();

        $model = $this->find($id);

        if ($model === null) {
            throw new \RuntimeException('Inserted record not found');
        }

        // Apply eager loading if enabled
        if ($this->eagerLoadingService && $this->eagerLoadingService->hasRelations()) {
            $items = $this->eagerLoadingService->loadRelations([$model], [$this, 'loadEagerRelations']);
            $this->eagerLoadingService->reset();
            return $items[0];
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int|string $id, array $data): object
    {
        $this->assertSafeIdentifier($this->primaryKey);

        $qb = $this->queryFactory->updateByIdBuilder($this->primaryKey, $id);

        foreach ($data as $column => $value) {
            $qb->set($column, ':' . $column)
               ->setParameter($column, $value);
        }

        $qb->executeStatement();

        $model = $this->find($id);

        if ($model === null) {
            throw new \RuntimeException('Updated record not found');
        }

        // Apply eager loading if enabled
        if ($this->eagerLoadingService && $this->eagerLoadingService->hasRelations()) {
            $items = $this->eagerLoadingService->loadRelations([$model], [$this, 'loadEagerRelations']);
            $this->eagerLoadingService->reset();
            return $items[0];
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $data
     * @return TModel
     * @throws \RuntimeException
     */

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $data
     */
    public function updateBy(array $criteria, array $data): int
    {
        $qb = $this->connection->createQueryBuilder()
            ->update($this->table);

        foreach ($data as $column => $value) {
            $qb->set($column, ':' . $column)
               ->setParameter($column, $value);
        }

        $qb = $this->applyCriteria($qb, $criteria, false);

        return (int) $qb->executeStatement();
    }

    public function delete(int|string $id): int
    {
        if ($this->softDeleteService) {
            return $this->updateBy([$this->primaryKey => $id], $this->softDeleteService->getSoftDeleteData());
        }

        return $this->forceDelete($id);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function deleteBy(array $criteria): int
    {
        if ($this->softDeleteService) {
            return $this->updateBy($criteria, $this->softDeleteService->getSoftDeleteData());
        }

        return $this->forceDeleteBy($criteria);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function exists(array $criteria): bool
    {
        // Apply soft delete logic if enabled
        if ($this->softDeleteService) {
            $criteria = $this->softDeleteService->applyCriteria($criteria);
        }

        $qb = $this->applyCriteria($this->table(), $criteria)
            ->select('1')
            ->setMaxResults(1);

        $result = $qb->executeQuery()->fetchOne();
        return $result !== false;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria): int
    {
        // Apply soft delete logic if enabled
        if ($this->softDeleteService) {
            $criteria = $this->softDeleteService->applyCriteria($criteria);
        }

        $qb = $this->applyCriteria($this->table(), $criteria)
            ->select('COUNT(*)');

        /** @var int|string|false $value */
        $value = $qb->executeQuery()->fetchOne();
        if ($value === false) {
            return 0;
        }
        return (int) $value;
    }

    public function beginTransaction(): bool
    {
        $this->connection->beginTransaction();
        return $this->connection->isTransactionActive();
    }

    public function commit(): bool
    {
        $this->connection->commit();
        return true;
    }

    public function rollBack(): bool
    {
        $this->connection->rollBack();
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->connection->isTransactionActive();
    }

    /**
     * @template TReturn
     * @param callable(RepositoryInterface<TModel>): TReturn $callback
     * @return TReturn
     */
    public function withTransaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $criteria
     */
    protected function applyCriteria(QueryBuilder $qb, array $criteria, bool $useAlias = true): QueryBuilder
    {
        return $this->criteriaBuilder->applyCriteria($qb, $criteria, $useAlias);
    }

    /**
     * @param array<string, 'ASC'|'DESC'> $orderBy
     */
    protected function applyOrderBy(QueryBuilder $qb, array $orderBy): void
    {
        $this->criteriaBuilder->applyOrderBy($qb, $orderBy);
    }

    /**
     * @param array<string, mixed> $row
     * @return TModel
     */
    protected function mapRowToModel(array $row): object
    {
        return $this->modelMapper->map($row);
    }



    protected function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Unsafe identifier: {$identifier}");
        }
    }

    // Soft delete methods

    /**
     * Force delete (permanent)
     */
    public function forceDelete(int|string $id): int
    {
        $this->assertSafeIdentifier($this->primaryKey);

        return (int) $this->queryFactory->deleteByIdBuilder($this->primaryKey, $id)
            ->executeStatement();
    }

    /**
     * Force delete by criteria (permanent)
     */
    public function forceDeleteBy(array $criteria): int
    {
        $qb = $this->connection->createQueryBuilder()
            ->delete($this->table);

        $qb = $this->applyCriteria($qb, $criteria, false);

        return (int) $qb->executeStatement();
    }

    /**
     * Restore soft deleted record
     */
    public function restore(int|string $id): int
    {
        if ($this->softDeleteService) {
            return $this->updateBy([$this->primaryKey => $id], $this->softDeleteService->getRestoreData());
        }
        return 0;
    }

    // Eager loading methods

    /**
     * Specify relations to eager load
     */
    public function with(array $relations): static
    {
        if ($this->eagerLoadingService) {
            $this->eagerLoadingService->setRelations($relations);
        }
        return $this;
    }

    /**
     * Load eager relations for items
     */
    public function loadEagerRelations(array $items, array $relations): array
    {
        if (empty($this->relationConfig) || empty($items)) {
            return $items;
        }

        foreach ($relations as $relation) {
            $this->eagerLoadingService->loadRelation($items, $relation, $this->relationConfig, $this);
        }

        return $items;
    }
}
