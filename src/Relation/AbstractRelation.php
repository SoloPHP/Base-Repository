<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Relation;

abstract readonly class AbstractRelation implements RelationType
{
    /**
     * @param array<string, string> $orderBy
     */
    public function __construct(
        public RelationKind $kind,
        public string $repository,
        public string $setter,
        public array $orderBy = [],
    ) {
    }

    public function kind(): RelationKind
    {
        return $this->kind;
    }
}
