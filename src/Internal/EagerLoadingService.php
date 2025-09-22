<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

class EagerLoadingService
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
    public function loadRelation(array $items, string $relation, array $relationConfig, object $repository): void
    {
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

        if ($type === 'belongsTo') {
            $ids = $this->pluckUnique($items, $foreignKey);
            if (!empty($ids)) {
                $related = $relatedRepository->findBy(['id' => $ids]);
                $this->joinBelongsTo($items, $related, $foreignKey, $setter);
            }
        } elseif ($type === 'hasMany') {
            $ids = $this->pluckIds($items);
            if (empty($ids)) {
                foreach ($items as $item) {
                    $item->{$setter}([]);
                }
                return;
            }
            $related = $relatedRepository->findBy([$foreignKey => $ids], $sort);
            $this->joinHasMany($items, $related, $foreignKey, $setter);
        }
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
        if (empty($items)) {
            return;
        }

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
     * Extract unique values from items array by field
     */
    private function pluckUnique(array $items, string $field): array
    {
        $values = [];
        foreach ($items as $item) {
            $value = $item->{$field} ?? null;
            if ($value !== null) {
                $values[$value] = true;
            }
        }
        return array_keys($values);
    }

    /**
     * Extract IDs from items array
     */
    private function pluckIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item->id;
        }
        return $ids;
    }
}
