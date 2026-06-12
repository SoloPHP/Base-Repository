<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Shared state for one CriteriaBuilder::build() invocation.
 *
 * Immutable per-scope; the counter is on a separate object so it can be
 * shared between the parent context and sub-contexts created for EXISTS
 * subquery bodies (parameter names like :_cb_p1 must stay globally unique).
 *
 * @phpstan-import-type RelationMeta from CriteriaBuilder
 *
 * @internal
 */
final readonly class CompileContext
{
    /**
     * @param array<string, RelationMeta> $compiledRelations
     */
    public function __construct(
        public QueryBuilder $qb,
        public string $baseAlias,
        public string $basePrimaryKey,
        public array $compiledRelations,
        public bool $useAlias,
        public ?string $currentLocale,
        public CompileCounter $counter,
    ) {
    }

    /**
     * Context for an EXISTS subquery body: switches base alias/PK, drops
     * relations and locale (the caller has already rewritten translated
     * field keys, so the inner build needs no further locale awareness).
     */
    public function forSubquery(string $alias, string $primaryKey): self
    {
        return new self(
            $this->qb,
            $alias,
            $primaryKey,
            [],
            true,
            null,
            $this->counter,
        );
    }
}
