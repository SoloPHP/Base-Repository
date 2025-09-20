<?php

declare(strict_types=1);

namespace Solo\BaseRepository;

trait WithRelations
{
    private array $eagerLoad = [];

    /**
     * Specify relations to eager load
     * @param array $relations
     * @return $this
     */
    public function with(array $relations): self
    {
        $this->eagerLoad = $relations;
        return $this;
    }

    /**
     * Override getBy to support eager loading
     */
    public function getBy(array $criteria = [], ?array $orderBy = null, ?int $perPage = null, ?int $page = null): array
    {
        $items = parent::getBy($criteria, $orderBy, $perPage, $page);
        return $this->loadEagerRelations($items);
    }

    /**
     * Override getById to support eager loading
     */
    public function getById(int|string $id): ?object
    {
        $item = parent::getById($id);
        if ($item === null) {
            return null;
        }
        $items = $this->loadEagerRelations([$item]);
        return $items[0] ?? null;
    }

    /**
     * Override getFirstBy to support eager loading
     */
    public function getFirstBy(array $criteria, ?array $orderBy = null): ?object
    {
        $item = parent::getFirstBy($criteria, $orderBy);
        if ($item === null) {
            return null;
        }
        $items = $this->loadEagerRelations([$item]);
        return $items[0] ?? null;
    }

    /**
     * Override getAll to support eager loading
     */
    public function getAll(): array
    {
        $items = parent::getAll();
        return $this->loadEagerRelations($items);
    }

    /**
     * Override insertAndGet to support eager loading
     */
    public function insertAndGet(array $data): object
    {
        $item = parent::insertAndGet($data);
        $items = $this->loadEagerRelations([$item]);
        return $items[0];
    }

    /**
     * Override updateAndGet to support eager loading
     */
    public function updateAndGet(int|string $id, array $data): object
    {
        $item = parent::updateAndGet($id, $data);
        $items = $this->loadEagerRelations([$item]);
        return $items[0];
    }

    /**
     * Load eager relations for items
     * @param array $items
     * @return array
     */
    protected function loadEagerRelations(array $items): array
    {
        if (empty($this->eagerLoad) || empty($items)) {
            return $items;
        }

        foreach ($this->eagerLoad as $relation) {
            $this->loadRelation($items, $relation);
        }

        // Reset eager load after using
        $this->eagerLoad = [];

        return $items;
    }

    /**
     * Load a specific relation
     * @param array $items
     * @param string $relation
     */
    protected function loadRelation(array $items, string $relation): void
    {
        $config = $this->getRelationConfig()[$relation] ?? null;
        if (!$config) {
            return;
        }

        $type = $config[0];
        $repositoryProperty = $config[1];
        $foreignKey = $config[2];
        $setter = $config[3];
        $sort = $config[4] ?? [];

        $repository = $this->{$repositoryProperty};

        if ($type === 'belongsTo') {
            $ids = $this->pluckUnique($items, $foreignKey);
            if (!empty($ids)) {
                $related = $repository->getBy(['id' => $ids]);
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
            $related = $repository->getBy([$foreignKey => $ids], $sort);
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
     * @param array $items
     * @param string $field
     * @return array
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
     * @param array $items
     * @return array
     */
    private function pluckIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item->id;
        }
        return $ids;
    }

    /**
     * Get relation configuration
     * Should return array of relations in format:
     * [
     *     'relationName' => [type, repositoryProperty, foreignKey, setter, ?sort]
     * ]
     * @return array
     */
    abstract protected function getRelationConfig(): array;
}