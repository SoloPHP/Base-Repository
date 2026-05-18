<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Relation;

final readonly class HasMany extends AbstractRelation
{
    /**
     * @param string $repository Repository property name
     * @param string $foreignKey Foreign key column in related table
     * @param string $setter Setter method name in model
     * @param array<string, string> $orderBy Optional ordering
     */
    public function __construct(
        string $repository,
        public string $foreignKey,
        string $setter,
        array $orderBy = [],
    ) {
        parent::__construct(RelationKind::HasMany, $repository, $setter, $orderBy);
    }
}
