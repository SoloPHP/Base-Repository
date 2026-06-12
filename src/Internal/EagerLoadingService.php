<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Solo\BaseRepository\Relation\AbstractRelation;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\BelongsToMany;
use Solo\BaseRepository\Relation\HasMany;
use Solo\BaseRepository\Relation\HasOne;
use Solo\BaseRepository\Relation\RelationType;

/**
 * @internal
 */
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
     * Load a specific relation. If $locale is non-null, propagates it — together
     * with the optional $fallbackLocale — to the related repository via withLocale()
     * before fetching, so related records resolve translations under the same locale
     * rules as the parent.
     *
     * @param array<string, RelationType> $relationConfig
     */
    public function loadRelation(
        array $items,
        string $relation,
        array $relationConfig,
        object $repository,
        array $nested = [],
        ?string $locale = null,
        ?string $fallbackLocale = null,
    ): void {
        $config = $relationConfig[$relation] ?? null;
        if ($config === null) {
            return; // unknown relation names are ignored
        }

        if (!$config instanceof AbstractRelation) {
            throw new \InvalidArgumentException(
                "Invalid relation config '{$relation}': expected a Relation instance, got " . get_debug_type($config)
            );
        }

        if ($config->setter === '') {
            throw new \InvalidArgumentException(
                "Relation '{$relation}' is filter-only (no setter configured) and cannot be eager loaded."
            );
        }

        $relatedRepository = $repository->{$config->repository};
        $parentPrimaryKey = $repository->getPrimaryKeyName();
        $relatedPrimaryKey = $relatedRepository->getPrimaryKeyName();

        if ($locale !== null && method_exists($relatedRepository, 'withLocale')) {
            $relatedRepository->withLocale($locale, $fallbackLocale);
        }

        if (!empty($nested) && method_exists($relatedRepository, 'with')) {
            $relatedRepository->with($nested);
        }

        $setter = $config->setter;
        $orderBy = $config->orderBy;

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
                // List-form wrapper keeps this machine-generated column key out
                // of reach of the related repository's scopes() expansion.
                $related = $relatedRepository->findBy([[$relatedPrimaryKey => $ids]]);
                $this->joinBelongsTo($items, $related, $config->foreignKey, $setter, $relatedPrimaryKey);
            }
        } elseif ($config instanceof HasMany) {
            $ids = $this->pluckIds($items, $parentPrimaryKey);
            $related = $relatedRepository->findBy([[$config->foreignKey => $ids]], $orderBy);
            $this->joinHasMany($items, $related, $config->foreignKey, $setter, $parentPrimaryKey);
        } elseif ($config instanceof HasOne) {
            $ids = $this->pluckIds($items, $parentPrimaryKey);
            $related = $relatedRepository->findBy([[$config->foreignKey => $ids]], $orderBy);
            $this->joinHasOne($items, $related, $config->foreignKey, $setter, $parentPrimaryKey);
        }

        // The related repo's locale and nested eager-load list are one-shot and
        // are cleared only when its findBy runs. If a branch above skipped
        // findBy (empty ids or empty pivot), clear them here so they can't
        // leak into the repo's next query.
        if ($locale !== null && method_exists($relatedRepository, 'withoutLocale')) {
            $relatedRepository->withoutLocale();
        }
        if (!empty($nested) && method_exists($relatedRepository, 'with')) {
            $relatedRepository->with([]);
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

        $pivotRows = $connection->fetchAllAssociative(
            "SELECT {$foreignPivotKey}, {$relatedPivotKey} FROM {$pivotTable} WHERE {$foreignPivotKey} IN (?)",
            [$parentIds],
            [Identifier::arrayParamTypeFor($parentIds)]
        );

        if (empty($pivotRows)) {
            foreach ($items as $item) {
                $item->{$setter}([]);
            }
            return;
        }

        $pivotMap = [];
        $relatedIds = [];
        foreach ($pivotRows as $row) {
            $pivotMap[$row[$foreignPivotKey]][] = $row[$relatedPivotKey];
            $relatedIds[] = $row[$relatedPivotKey];
        }
        $relatedIds = array_values(array_unique($relatedIds));

        // List-form wrapper keeps this machine-generated column key out of
        // reach of the related repository's scopes() expansion.
        $relatedItems = $relatedRepository->findBy([[$relatedPrimaryKey => $relatedIds]], $sort);

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

        $relatedMap = [];
        foreach ($related as $item) {
            $relatedMap[$item->{$relatedPrimaryKey}] = $item;
        }

        foreach ($items as $item) {
            if ($item->{$foreignKey} && isset($relatedMap[$item->{$foreignKey}])) {
                $item->{$setter}($relatedMap[$item->{$foreignKey}]);
            }
        }
    }

    private function joinHasMany(
        array $items,
        array $related,
        string $foreignKey,
        string $setter,
        string $parentPrimaryKey
    ): void {
        $relatedMap = [];
        foreach ($related as $item) {
            $relatedMap[$item->{$foreignKey}][] = $item;
        }

        foreach ($items as $item) {
            $item->{$setter}($relatedMap[$item->{$parentPrimaryKey}] ?? []);
        }
    }

    /**
     * hasOne keeps the first match per foreign key (first wins).
     */
    private function joinHasOne(
        array $items,
        array $related,
        string $foreignKey,
        string $setter,
        string $parentPrimaryKey
    ): void {
        $relatedMap = [];
        foreach ($related as $item) {
            if (!isset($relatedMap[$item->{$foreignKey}])) {
                $relatedMap[$item->{$foreignKey}] = $item;
            }
        }

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
