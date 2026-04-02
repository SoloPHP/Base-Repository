<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Solo\BaseRepository\Relation\RelationType;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\BelongsToMany;
use Solo\BaseRepository\Relation\HasMany;
use Solo\BaseRepository\Relation\HasOne;

/**
 * @internal
 */
final class EagerLoadingService
{
    private array $eagerLoad = [];
    private ?string $locale = null;

    /**
     * Set relations to eager load
     */
    public function setRelations(array $relations): void
    {
        $this->eagerLoad = $relations;
    }

    /**
     * Set locale to propagate to related repositories
     */
    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
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
        $this->locale = null;
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
        $parentPrimaryKey = $repository->getPrimaryKeyName();
        $relatedPrimaryKey = $relatedRepository->getPrimaryKeyName();

        // Propagate locale to related repository for translation support
        if ($this->locale !== null && method_exists($relatedRepository, 'withLocale')) {
            $relatedRepository->withLocale($this->locale);
        }

        // If nested relations are requested, configure them on the related repository
        if (!empty($nested) && method_exists($relatedRepository, 'with')) {
            $relatedRepository->with($nested);
        }

        $setter = $config->getSetter();
        $orderBy = $config->getOrderBy();

        if ($config instanceof BelongsToMany) {
            $ids = $this->pluckIds($items, $parentPrimaryKey);
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
                    $orderBy,
                    $parentPrimaryKey
                );
            }
        } elseif ($config instanceof BelongsTo) {
            $ids = $this->pluckUnique($items, $config->foreignKey);
            if (!empty($ids)) {
                $related = $relatedRepository->findBy([$relatedPrimaryKey => $ids]);
                $this->joinBelongsTo($items, $related, $config->foreignKey, $setter, $relatedPrimaryKey);
            }
        } elseif ($config instanceof HasMany) {
            $ids = $this->pluckIds($items, $parentPrimaryKey);
            $related = $relatedRepository->findBy([$config->foreignKey => $ids], $orderBy);
            $this->joinHasMany($items, $related, $config->foreignKey, $setter, $parentPrimaryKey);
        } elseif ($config instanceof HasOne) {
            $ids = $this->pluckIds($items, $parentPrimaryKey);
            $related = $relatedRepository->findBy([$config->foreignKey => $ids], $orderBy);
            $this->joinHasOne($items, $related, $config->foreignKey, $setter, $parentPrimaryKey);
        }
    }

    /**
     * Load belongsToMany relations via pivot table.
     * Uses two queries: pivot rows + findBy() on related repository
     * (supports translations, scopes, and other repository features).
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
        array $sort,
        string $parentPrimaryKey
    ): void {
        $connection = $parentRepository->getConnection();
        $relatedPrimaryKey = $relatedRepository->getPrimaryKeyName();

        // Determine parameter type based on ID type
        $paramType = !empty($parentIds) && is_int($parentIds[0])
            ? \Doctrine\DBAL\ArrayParameterType::INTEGER
            : \Doctrine\DBAL\ArrayParameterType::STRING;

        // Step 1: Get pivot rows (parent_id → related_id mapping)
        $pivotRows = $connection->fetchAllAssociative(
            "SELECT {$foreignPivotKey}, {$relatedPivotKey} FROM {$pivotTable} WHERE {$foreignPivotKey} IN (?)",
            [$parentIds],
            [$paramType]
        );

        if (empty($pivotRows)) {
            foreach ($items as $item) {
                $item->{$setter}([]);
            }
            return;
        }

        // Build parent→related mapping
        $pivotMap = [];
        $relatedIds = [];
        foreach ($pivotRows as $row) {
            $pivotMap[$row[$foreignPivotKey]][] = $row[$relatedPivotKey];
            $relatedIds[] = $row[$relatedPivotKey];
        }
        $relatedIds = array_values(array_unique($relatedIds));

        // Step 2: Load related models via repository (supports translations, scopes, etc.)
        $relatedItems = $relatedRepository->findBy([$relatedPrimaryKey => $relatedIds], $sort);

        // Index related items by primary key
        $relatedMap = [];
        foreach ($relatedItems as $item) {
            $relatedMap[$item->{$relatedPrimaryKey}] = $item;
        }

        // Step 3: Group by parent ID, preserving findBy() sort order
        $reverseMap = [];
        foreach ($pivotMap as $parentId => $relIds) {
            foreach ($relIds as $relId) {
                $reverseMap[$relId][] = $parentId;
            }
        }

        $grouped = [];
        foreach ($relatedItems as $item) {
            $relId = $item->{$relatedPrimaryKey};
            if (isset($reverseMap[$relId])) {
                foreach ($reverseMap[$relId] as $parentId) {
                    $grouped[$parentId][] = $item;
                }
            }
        }

        // Set relations on each item
        foreach ($items as $item) {
            $item->{$setter}($grouped[$item->{$parentPrimaryKey}] ?? []);
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
    private function joinBelongsTo(
        array $items,
        array $related,
        string $foreignKey,
        string $setter,
        string $relatedPrimaryKey
    ): void {
        if (empty($items) || empty($related)) {
            return;
        }

        // Map related items by their primary key
        $relatedMap = [];
        foreach ($related as $item) {
            $relatedMap[$item->{$relatedPrimaryKey}] = $item;
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
    private function joinHasMany(
        array $items,
        array $related,
        string $foreignKey,
        string $setter,
        string $parentPrimaryKey
    ): void {
        // Group related items by foreign key
        $relatedMap = [];
        foreach ($related as $item) {
            $relatedMap[$item->{$foreignKey}][] = $item;
        }

        // Set relations
        foreach ($items as $item) {
            $item->{$setter}($relatedMap[$item->{$parentPrimaryKey}] ?? []);
        }
    }

    /**
     * Join has-one relations
     */
    private function joinHasOne(
        array $items,
        array $related,
        string $foreignKey,
        string $setter,
        string $parentPrimaryKey
    ): void {
        // Map related items by foreign key (taking first match for each foreign key)
        $relatedMap = [];
        foreach ($related as $item) {
            if (!isset($relatedMap[$item->{$foreignKey}])) {
                $relatedMap[$item->{$foreignKey}] = $item;
            }
        }

        // Set relations
        foreach ($items as $item) {
            $item->{$setter}($relatedMap[$item->{$parentPrimaryKey}] ?? null);
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
     * Extract primary key values from items array
     */
    private function pluckIds(array $items, string $primaryKey): array
    {
        return array_column($items, $primaryKey);
    }
}
