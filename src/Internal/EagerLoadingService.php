<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Solo\BaseRepository\Relation\RelationType;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\BelongsToMany;
use Solo\BaseRepository\Relation\HasMany;
use Solo\BaseRepository\Relation\HasOne;

final class EagerLoadingService
{
    private array $eagerLoad = [];

    /**
     * Set relations to eager load
     */
    public function setRelations(array $relations): void
    {
        $this->eagerLoad = $relations;
    }

    /**
     * Check if there are relations to load
     */
    public function hasRelations(): bool
    {
        return !empty($this->eagerLoad);
    }

    /**
     * Get current relations
     */
    public function getRelations(): array
    {
        return $this->eagerLoad;
    }

    /**
     * Load relations using provided callback
     */
    public function loadRelations(array $items, callable $relationLoader): array
    {
        if (empty($this->eagerLoad) || empty($items)) {
            return $items;
        }

        return $relationLoader($items, $this->eagerLoad);
    }

    /**
     * Reset eager load state
     */
    public function reset(): void
    {
        $this->eagerLoad = [];
    }

    /**
     * Load a specific relation
     *
     * @param array<string, RelationType> $relationConfig
     */
    public function loadRelation(
        array $items,
        string $relation,
        array $relationConfig,
        object $repository,
        array $nested = []
    ): void {
        $config = $relationConfig[$relation] ?? null;
        if (!$config instanceof RelationType) {
            return;
        }

        $relatedRepository = $repository->{$config->getRepository()};

        // If nested relations are requested, configure them on the related repository
        if (!empty($nested) && method_exists($relatedRepository, 'with')) {
            $relatedRepository->with($nested);
        }

        $setter = $config->getSetter();
        $orderBy = $config->getOrderBy();

        if ($config instanceof BelongsToMany) {
            $ids = $this->pluckIds($items);
            if (!empty($ids)) {
                $this->loadBelongsToMany(
                    $items,
                    $ids,
                    $relatedRepository,
                    $repository,
                    $config->pivot,
                    $config->foreignPivotKey,
                    $config->relatedPivotKey,
                    $setter,
                    $orderBy
                );
            }
        } elseif ($config instanceof BelongsTo) {
            $ids = $this->pluckUnique($items, $config->foreignKey);
            if (!empty($ids)) {
                $related = $relatedRepository->findBy(['id' => $ids]);
                $this->joinBelongsTo($items, $related, $config->foreignKey, $setter);
            }
        } elseif ($config instanceof HasMany) {
            $ids = $this->pluckIds($items);
            $related = $relatedRepository->findBy([$config->foreignKey => $ids], $orderBy);
            $this->joinHasMany($items, $related, $config->foreignKey, $setter);
        } elseif ($config instanceof HasOne) {
            $ids = $this->pluckIds($items);
            $related = $relatedRepository->findBy([$config->foreignKey => $ids], $orderBy);
            $this->joinHasOne($items, $related, $config->foreignKey, $setter);
        }
    }

    /**
     * Load belongsToMany relations via pivot table using single JOIN query
     */
    private function loadBelongsToMany(
        array $items,
        array $parentIds,
        object $relatedRepository,
        object $parentRepository,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $setter,
        array $sort
    ): void {
        $connection = $parentRepository->getConnection();
        $relatedTable = $relatedRepository->getTableName();
        $relatedPrimaryKey = $relatedRepository->getPrimaryKeyName();

        // Single JOIN query: pivot + related table
        $qb = $connection->createQueryBuilder()
            ->select("p.{$foreignPivotKey}", 'r.*')
            ->from($pivotTable, 'p')
            ->innerJoin('p', $relatedTable, 'r', "r.{$relatedPrimaryKey} = p.{$relatedPivotKey}")
            ->where("p.{$foreignPivotKey} IN (:ids)")
            ->setParameter('ids', $parentIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);

        // Apply sorting
        foreach ($sort as $column => $direction) {
            $qb->addOrderBy("r.{$column}", $direction);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        if (empty($rows)) {
            foreach ($items as $item) {
                $item->{$setter}([]);
            }
            return;
        }

        // Group by parent ID and map to models
        $grouped = [];
        foreach ($rows as $row) {
            $parentId = $row[$foreignPivotKey];
            unset($row[$foreignPivotKey]);
            $grouped[$parentId][] = $relatedRepository->mapToModel($row);
        }

        // Set relations on each item
        foreach ($items as $item) {
            $item->{$setter}($grouped[$item->id] ?? []);
        }
    }

    /**
     * Group relations by their top-level part and collect nested segments for each.
     *
     * Example:
     *   ["comments", "comments.user", "author.profile.avatar"]
     * becomes
     *   [
     *     'comments' => ['user'],
     *     'author' => ['profile.avatar']
     *   ]
     */
    public function groupByTopLevel(array $relations): array
    {
        $grouped = [];
        foreach ($relations as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $parts = explode('.', $path);
            $top = array_shift($parts);
            if ($top === '') {
                continue;
            }
            if (!array_key_exists($top, $grouped)) {
                $grouped[$top] = [];
            }
            if (!empty($parts)) {
                $grouped[$top][] = implode('.', $parts);
            }
        }
        return $grouped;
    }

    /**
     * Join belongs-to relations
     */
    private function joinBelongsTo(array $items, array $related, string $foreignKey, string $setter): void
    {
        if (empty($items) || empty($related)) {
            return;
        }

        // Map related items by key
        $relatedMap = [];
        foreach ($related as $item) {
            $relatedMap[$item->id] = $item;
        }

        // Set relations
        foreach ($items as $item) {
            if ($item->{$foreignKey} && isset($relatedMap[$item->{$foreignKey}])) {
                $item->{$setter}($relatedMap[$item->{$foreignKey}]);
            }
        }
    }

    /**
     * Join has-many relations
     */
    private function joinHasMany(array $items, array $related, string $foreignKey, string $setter): void
    {
        // Group related items by foreign key
        $relatedMap = [];
        foreach ($related as $item) {
            $relatedMap[$item->{$foreignKey}][] = $item;
        }

        // Set relations
        foreach ($items as $item) {
            $item->{$setter}($relatedMap[$item->id] ?? []);
        }
    }

    /**
     * Join has-one relations
     */
    private function joinHasOne(array $items, array $related, string $foreignKey, string $setter): void
    {
        // Map related items by foreign key (taking first match for each foreign key)
        $relatedMap = [];
        foreach ($related as $item) {
            if (!isset($relatedMap[$item->{$foreignKey}])) {
                $relatedMap[$item->{$foreignKey}] = $item;
            }
        }

        // Set relations
        foreach ($items as $item) {
            $item->{$setter}($relatedMap[$item->id] ?? null);
        }
    }

    /**
     * Extract unique non-null values from items array by field
     */
    private function pluckUnique(array $items, string $field): array
    {
        return array_values(array_unique(array_filter(
            array_column($items, $field),
            fn($v) => $v !== null
        )));
    }

    /**
     * Extract IDs from items array
     */
    private function pluckIds(array $items): array
    {
        return array_column($items, 'id');
    }
}
