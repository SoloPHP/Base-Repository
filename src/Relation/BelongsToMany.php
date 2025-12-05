<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Relation;

final readonly class BelongsToMany implements RelationType
{
    /**
     * @param string $repository Repository property name
     * @param string $pivot Pivot table name
     * @param string $foreignPivotKey Foreign key in pivot pointing to current model
     * @param string $relatedPivotKey Foreign key in pivot pointing to related model
     * @param string $setter Setter method name in model
     * @param array<string, string> $orderBy Optional ordering
     */
    public function __construct(
        public string $repository,
        public string $pivot,
        public string $foreignPivotKey,
        public string $relatedPivotKey,
        public string $setter,
        public array $orderBy = [],
    ) {
    }

    public function getType(): string
    {
        return 'belongsToMany';
    }

    public function getRepository(): string
    {
        return $this->repository;
    }

    public function getSetter(): string
    {
        return $this->setter;
    }

    public function getOrderBy(): array
    {
        return $this->orderBy;
    }
}
