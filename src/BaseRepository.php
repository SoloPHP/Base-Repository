<?php

declare(strict_types=1);

namespace Solo\BaseRepository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Solo\BaseRepository\Internal\ModelMapper;
use Solo\BaseRepository\Internal\QueryFactory;
use Solo\BaseRepository\Internal\CriteriaBuilder;
use Solo\BaseRepository\Internal\SoftDeleteService;
use Solo\BaseRepository\Internal\EagerLoadingService;
use Solo\BaseRepository\Internal\RelationCriteriaApplier;
use Solo\BaseRepository\Relation\RelationType;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\BelongsToMany;
use Solo\BaseRepository\Relation\HasMany;
use Solo\BaseRepository\Relation\HasOne;

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
    protected RelationCriteriaApplier $relationCriteriaApplier;

    protected ?SoftDeleteService $softDeleteService = null;
    protected ?EagerLoadingService $eagerLoadingService = null;

    // Configuration properties - override in child classes
    protected ?string $deletedAtColumn = null;
    /** @var array<string, RelationType|mixed> */
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
        $this->criteriaBuilder = new CriteriaBuilder($this->getTableAlias());
        $this->modelMapper = new ModelMapper($this->modelClass, $this->mapperMethod);
        $this->queryFactory = new QueryFactory($this->connection, $this->table, $this->getTableAlias());
        $this->initializeServices();
        $this->relationCriteriaApplier = new RelationCriteriaApplier($this->connection);
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
    }

    /**
     * Get or create EagerLoadingService (lazy initialization)
     */
    private function getEagerLoadingService(): EagerLoadingService
    {
        if ($this->eagerLoadingService === null) {
            $this->eagerLoadingService = new EagerLoadingService();
        }
        return $this->eagerLoadingService;
    }

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
    public function getTableName(): string
    {
        return $this->table;
    }

    /**
     * @return non-empty-string
     */
    public function getPrimaryKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get the database connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Map a database row to a model instance
     *
     * @param array<string, mixed> $row
     * @return TModel
     */
    public function mapToModel(array $row): object
    {
        return $this->mapRowToModel($row);
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

        // Use provided ID or get auto-generated one
        $id = $data[$this->primaryKey] ?? $this->connection->lastInsertId();

        /** @var TModel */
        return $this->find($id);
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

        return $model;
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $data
     */
    public function updateBy(array $criteria, array $data): int
    {
        $qb = $this->connection->createQueryBuilder()
            ->update($this->table);

        // Set update values with prefixed parameter names to avoid conflicts with criteria parameters
        foreach ($data as $column => $value) {
            $paramName = 'update_' . $column;
            $qb->set($column, ':' . $paramName)
                ->setParameter($paramName, $value);
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

        return (int) $qb->executeQuery()->fetchOne();
    }

    public function sum(string $column, array $criteria = []): int|float
    {
        return (float) $this->aggregate('SUM', $column, $criteria);
    }

    public function avg(string $column, array $criteria = []): int|float
    {
        return (float) $this->aggregate('AVG', $column, $criteria);
    }

    public function min(string $column, array $criteria = []): mixed
    {
        return $this->aggregate('MIN', $column, $criteria);
    }

    public function max(string $column, array $criteria = []): mixed
    {
        return $this->aggregate('MAX', $column, $criteria);
    }

    /**
     * @param string $function
     * @param string $column
     * @param array<string, mixed> $criteria
     * @return mixed
     */
    protected function aggregate(string $function, string $column, array $criteria): mixed
    {
        // Apply soft delete logic if enabled
        if ($this->softDeleteService) {
            $criteria = $this->softDeleteService->applyCriteria($criteria);
        }

        $this->assertSafeIdentifier($column);

        // Use table alias for ambiguity resolution
        $columnName = $this->getTableAlias() . '.' . $column;

        $qb = $this->queryFactory->builder()
            ->select(sprintf('%s(%s)', $function, $columnName))
            ->from($this->table, $this->getTableAlias());

        $qb = $this->applyCriteria($qb, $criteria);

        return $qb->executeQuery()->fetchOne();
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
        // Support relation dot-notation in criteria, e.g. "relation.field" => value
        // Split incoming criteria into base-table criteria and relation criteria
        [$baseCriteria, $relationCriteria] = $this->extractRelationDotCriteria($criteria);

        // Apply base criteria via CriteriaBuilder (keeps existing behavior)
        $qb = $this->criteriaBuilder->applyCriteria($qb, $baseCriteria, $useAlias);

        // Apply relation criteria as EXISTS-subqueries
        if (!empty($relationCriteria)) {
            $compiledRelations = $this->compileRelationMetadata();
            $this->relationCriteriaApplier->apply(
                $qb,
                $this->getTableAlias(),
                $this->getPrimaryKeyName(),
                $compiledRelations,
                $relationCriteria
            );
        }

        return $qb;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    private function extractRelationDotCriteria(array $criteria): array
    {
        $baseCriteria = [];
        $relationCriteria = [];

        foreach ($criteria as $key => $value) {
            if (str_contains($key, '.')) {
                [$relation, $field] = explode('.', $key, 2);

                // Check for NOT EXISTS prefix (!)
                $isNotExists = false;
                if (str_starts_with($relation, '!')) {
                    $isNotExists = true;
                    $relation = substr($relation, 1);
                }

                // Only treat as relation filter if relation exists in relationConfig
                if (isset($this->relationConfig[$relation]) && $field !== '') {
                    // Store with ! prefix if NOT EXISTS
                    $relationKey = $isNotExists ? '!' . $relation : $relation;
                    $relationCriteria[$relationKey][$field] = $value;
                    continue;
                }
            }

            $baseCriteria[$key] = $value;
        }

        return [$baseCriteria, $relationCriteria];
    }

    /**
     * Compile relation metadata from relationConfig and repository graph.
     */
    private function compileRelationMetadata(): array
    {
        $result = [];
        foreach ($this->relationConfig as $relation => $config) {
            if (!$config instanceof RelationType) {
                continue;
            }

            $repositoryProperty = $config->getRepository();

            if (!property_exists($this, $repositoryProperty)) {
                continue;
            }

            $relatedRepo = $this->{$repositoryProperty};
            $relatedTable = $relatedRepo->getTableName();
            $relatedPrimaryKey = $relatedRepo->getPrimaryKeyName();

            if ($config instanceof BelongsToMany) {
                $result[$relation] = [
                    'type' => $config->getType(),
                    'relatedTable' => $relatedTable,
                    'relatedPrimaryKey' => $relatedPrimaryKey,
                    'pivotTable' => $config->pivot,
                    'foreignPivotKey' => $config->foreignPivotKey,
                    'relatedPivotKey' => $config->relatedPivotKey,
                ];
            } else {
                /** @var BelongsTo|HasMany|HasOne $config */
                $result[$relation] = [
                    'type' => $config->getType(),
                    'foreignKey' => $config->foreignKey,
                    'relatedTable' => $relatedTable,
                    'relatedPrimaryKey' => $relatedPrimaryKey,
                ];
            }
        }
        return $result;
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
        if (!empty($this->relationConfig)) {
            $this->getEagerLoadingService()->setRelations($relations);
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

        $service = $this->getEagerLoadingService();

        // Support nested relations via dot-notation using top-level grouping
        $grouped = $service->groupByTopLevel($relations);

        foreach ($grouped as $relation => $nested) {
            $service->loadRelation($items, $relation, $this->relationConfig, $this, $nested);
        }

        return $items;
    }
}
