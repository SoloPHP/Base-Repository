<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Relation;

final readonly class BelongsToMany extends AbstractRelation
{
    /**
     * @param string $repository Repository property name
     * @param string $pivot Pivot table name
     * @param string $foreignPivotKey Foreign key in pivot pointing to current model
     * @param string $relatedPivotKey Foreign key in pivot pointing to related model
     * @param string $setter Setter method name in model; omit for filter-only relations
     * @param array<string, string> $orderBy Optional ordering
     */
    public function __construct(
        string $repository,
        public string $pivot,
        public string $foreignPivotKey,
        public string $relatedPivotKey,
        string $setter = '',
        array $orderBy = [],
    ) {
        parent::__construct(RelationKind::BelongsToMany, $repository, $setter, $orderBy);
    }
}
