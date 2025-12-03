<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

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
     */
    public function loadRelation(
        array $items,
        string $relation,
        array $relationConfig,
        object $repository,
        array $nested = []
    ): void {
        $config = $relationConfig[$relation] ?? null;
        if (!$config) {
            return;
        }

        $type = $config[0];
        $repositoryProperty = $config[1];
        $foreignKey = $config[2];
        $setter = $config[3];
        $sort = $config[4] ?? [];

        $relatedRepository = $repository->{$repositoryProperty};

        // If nested relations are requested, configure them on the related repository
        if (!empty($nested) && method_exists($relatedRepository, 'with')) {
            // Nested parts (e.g. ["attribute", "attribute.translations"]) are relative to the related repository
            $relatedRepository->with($nested);
        }

        if ($type === 'belongsTo') {
            $ids = $this->pluckUnique($items, $foreignKey);
            if (!empty($ids)) {
                $related = $relatedRepository->findBy(['id' => $ids]);
                $this->joinBelongsTo($items, $related, $foreignKey, $setter);
            }
        } elseif ($type === 'hasMany') {
            $ids = $this->pluckIds($items);
            $related = $relatedRepository->findBy([$foreignKey => $ids], $sort);
            $this->joinHasMany($items, $related, $foreignKey, $setter);
        } elseif ($type === 'hasOne') {
            $ids = $this->pluckIds($items);
            $related = $relatedRepository->findBy([$foreignKey => $ids], $sort);
            $this->joinHasOne($items, $related, $foreignKey, $setter);
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
