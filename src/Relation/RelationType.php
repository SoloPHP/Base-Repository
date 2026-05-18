<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Relation;

/**
 * Marker interface for relation configs. Implementations should extend
 * AbstractRelation, which carries the common $repository/$setter/$orderBy
 * properties and implements kind().
 */
interface RelationType
{
    public function kind(): RelationKind;
}
