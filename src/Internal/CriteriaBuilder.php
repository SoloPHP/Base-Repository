<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\Query\QueryBuilder;
use Solo\BaseRepository\Relation\RelationKind;

/**
 * Recursive criteria → WHERE-expression compiler.
 *
 * The criteria tree is an associative array. Top level is implicitly AND-joined.
 *
 * Recognized entries
 * ──────────────────
 *   'OR'  / 'AND'   value MUST be an array → nested group joined by the named
 *                   connector. Groups recurse arbitrarily deep. Throws if
 *                   the value is not an array.
 *
 *   'rel.field'     If `rel` is a known relation, translates to an EXISTS
 *                   subquery against the related table. Multiple `rel.*` keys
 *                   in the same group share one EXISTS body (AND-joined inside).
 *                   Prefix with '!' for NOT EXISTS: '!rel.field'. Unknown
 *                   relation with '!' prefix throws (ambiguous intent).
 *                   Unknown relation without '!' falls through to a qualified
 *                   column leaf — useful for already-joined columns.
 *
 *   'field'         Leaf condition. Value forms:
 *                     null              → IS NULL
 *                     scalar            → equality
 *                     [v1, v2, ...]     → IN (...)  (empty list → 1 = 0)
 *                     ['op' => v]       → 'op' ∈ {=, !=, <>, <, >, <=, >=,
 *                                                  LIKE, NOT LIKE, IN, NOT IN, BETWEEN}
 *
 *   list-form group body                : ['OR' => [['a' => 1], ['b' => 2]]]
 *                   Lets a group contain repeating sub-criteria when the same
 *                   field needs different operators in disjunction.
 *
 * EXISTS subqueries are emitted as raw SQL strings; their parameter placeholders
 * (`:p1`, `:p2`, …) are bound on the outer QueryBuilder, since the whole query
 * is prepared and executed there. No inner QueryBuilder is needed.
 *
 * @phpstan-type Translation array{table: string, foreignKey: string, fields: list<string>}
 * @phpstan-type RelationMetaPivot array{
 *     kind: RelationKind::BelongsToMany,
 *     relatedTable: string,
 *     relatedPrimaryKey: string,
 *     pivotTable: string,
 *     foreignPivotKey: string,
 *     relatedPivotKey: string,
 *     translation?: Translation,
 * }
 * @phpstan-type RelationMetaSimple array{
 *     kind: RelationKind::BelongsTo|RelationKind::HasOne|RelationKind::HasMany,
 *     foreignKey: string,
 *     relatedTable: string,
 *     relatedPrimaryKey: string,
 *     translation?: Translation,
 * }
 * @phpstan-type RelationMeta RelationMetaPivot|RelationMetaSimple
 *
 * @internal
 */
final class CriteriaBuilder
{
    private const array ALLOWED_OPERATORS = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN',
    ];

    /**
     * Build a WHERE expression and bind its parameters on $qb.
     *
     * When $useAlias is false (UPDATE/DELETE context), $baseAlias must be the
     * actual table name; self-relation EXISTS subqueries are rejected because
     * MySQL forbids the UPDATE/DELETE target table inside a subquery FROM.
     *
     * $configuredRelations carries the full set of relation names from the
     * caller's config, including ones that compiled to nothing (invalid type
     * or missing repo property). Criteria targeting those are silently dropped
     * — the caller has already opted to tolerate broken config there.
     *
     * @param array<string|int, mixed>    $criteria
     * @param array<string, RelationMeta> $compiledRelations
     * @param array<string, true>         $configuredRelations
     */
    public function build(
        QueryBuilder $qb,
        array $criteria,
        string $baseAlias,
        string $basePrimaryKey,
        array $compiledRelations,
        array $configuredRelations = [],
        bool $useAlias = true,
        ?string $currentLocale = null,
    ): ?string {
        if ($criteria === []) {
            return null;
        }
        $ctx = new CompileContext(
            $qb,
            $baseAlias,
            $basePrimaryKey,
            $compiledRelations,
            $configuredRelations,
            $useAlias,
            $currentLocale,
            new CompileCounter(),
        );
        return $this->buildGroup($ctx, $criteria, 'AND');
    }

    /**
     * @param array<int, 'ASC'|'DESC'>|array<string, 'ASC'|'DESC'> $orderBy
     */
    public function applyOrderBy(QueryBuilder $qb, array $orderBy, string $baseAlias): void
    {
        foreach ($orderBy as $field => $direction) {
            $field = (string) $field;
            if (str_starts_with($field, '!')) {
                throw new \InvalidArgumentException("orderBy keys cannot have '!' prefix: {$field}");
            }
            Identifier::assertSafeCriteriaKey($field);
            $column = $this->qualify($field, $baseAlias);
            $dir = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
            $qb->addOrderBy($column, $dir);
        }
    }

    /**
     * @param array<string|int, mixed> $criteria
     */
    private function buildGroup(CompileContext $ctx, array $criteria, string $connector): ?string
    {
        // Aggregate same-relation dot-keys so they share one EXISTS per group.
        $relationGroups = [];
        $clauses = [];

        foreach ($criteria as $key => $value) {
            // List-form sub-criteria: each numeric-keyed entry is itself a group (AND).
            if (is_int($key)) {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException(
                        "List-form criteria entries must be arrays; got " . get_debug_type($value)
                    );
                }
                $sub = $this->buildGroup($ctx, $value, 'AND');
                if ($sub !== null) {
                    $clauses[] = $sub;
                }
                continue;
            }

            // Reserved group keys must carry an array value.
            if ($key === 'OR' || $key === 'AND') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException(
                        "'{$key}' group requires an array value; got " . get_debug_type($value)
                    );
                }
                $sub = $this->buildGroup($ctx, $value, $key);
                if ($sub !== null) {
                    $clauses[] = $sub;
                }
                continue;
            }

            Identifier::assertSafeCriteriaKey($key);

            // Relation dot-notation: aggregate by relation; emit one EXISTS per relation per group.
            if (str_contains($key, '.')) {
                [$rawRelation, $field] = explode('.', $key, 2);
                $isNotExists = str_starts_with($rawRelation, '!');
                $relation = $isNotExists ? substr($rawRelation, 1) : $rawRelation;

                if ($field !== '' && isset($ctx->compiledRelations[$relation])) {
                    $groupKey = ($isNotExists ? '!' : '') . $relation;
                    $relationGroups[$groupKey][$field] = $value;
                    continue;
                }

                // Configured but didn't compile (invalid type / missing repo property):
                // silently drop, matching the prior contract for tolerant configs.
                if (isset($ctx->configuredRelations[$relation])) {
                    continue;
                }

                if ($isNotExists) {
                    throw new \InvalidArgumentException(
                        "Unknown relation '{$relation}' for NOT EXISTS criterion: '{$key}'"
                    );
                }
                // Unknown relation without '!': fall through to qualified-column leaf.
            }

            $clauses[] = $this->buildLeaf($ctx, $key, $value);
        }

        foreach ($relationGroups as $relationKey => $fields) {
            $clauses[] = $this->buildRelationExists($ctx, $relationKey, $fields);
        }

        return $this->combine($connector, $clauses);
    }

    /**
     * @param array<int, string> $clauses
     */
    private function combine(string $connector, array $clauses): ?string
    {
        if ($clauses === []) {
            return null;
        }
        if (count($clauses) === 1) {
            return $clauses[0];
        }
        return '(' . implode(' ' . $connector . ' ', $clauses) . ')';
    }

    private function buildLeaf(CompileContext $ctx, string $field, mixed $value): string
    {
        $column = $this->qualify($field, $ctx->baseAlias, $ctx->useAlias);
        $param = '_cb_p' . $ctx->counter->next();

        if ($value === null) {
            return "{$column} IS NULL";
        }

        if (!is_array($value)) {
            $ctx->qb->setParameter($param, $value);
            return "{$column} = :{$param}";
        }

        if ($value === []) {
            return '1 = 0';
        }

        // List-form = IN(...) shortcut.
        if (is_int(array_key_first($value))) {
            $values = array_values($value);
            $ctx->qb->setParameter($param, $values, Identifier::arrayParamTypeFor($values));
            return "{$column} IN (:{$param})";
        }

        return $this->buildOperatorLeaf($ctx->qb, $column, $param, $value);
    }

    /**
     * @param non-empty-array<string, mixed> $value Operator-form: ['op' => v]
     */
    private function buildOperatorLeaf(QueryBuilder $qb, string $column, string $param, array $value): string
    {
        $operator = (string) array_key_first($value);
        $val = $value[$operator];
        $this->assertSafeOperator($operator);
        $upperOp = strtoupper($operator);

        if ($val === null) {
            return match ($upperOp) {
                '=' => "{$column} IS NULL",
                '!=', '<>' => "{$column} IS NOT NULL",
                default => throw new \InvalidArgumentException(
                    "Operator '{$operator}' does not accept null value"
                ),
            };
        }

        if ($upperOp === 'IN' || $upperOp === 'NOT IN') {
            $list = is_array($val) ? $val : [$val];
            if ($list === []) {
                return $upperOp === 'IN' ? '1 = 0' : '1 = 1';
            }
            $qb->setParameter($param, $list, Identifier::arrayParamTypeFor($list));
            return "{$column} {$upperOp} (:{$param})";
        }

        if ($upperOp === 'BETWEEN') {
            if (!is_array($val) || count($val) !== 2) {
                throw new \InvalidArgumentException('BETWEEN requires array of exactly 2 values');
            }
            $qb->setParameter("{$param}_min", $val[0]);
            $qb->setParameter("{$param}_max", $val[1]);
            return "{$column} BETWEEN :{$param}_min AND :{$param}_max";
        }

        if (is_array($val)) {
            throw new \InvalidArgumentException("Operator '{$operator}' does not accept array value");
        }

        $qb->setParameter($param, $val);
        return "{$column} {$operator} :{$param}";
    }

    private function qualify(string $field, string $baseAlias, bool $useAlias = true): string
    {
        if (!$useAlias || str_contains($field, '.')) {
            return $field;
        }
        return "{$baseAlias}.{$field}";
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function buildRelationExists(CompileContext $ctx, string $relationKey, array $fields): string
    {
        $isNotExists = str_starts_with($relationKey, '!');
        $relation = $isNotExists ? substr($relationKey, 1) : $relationKey;
        $compiled = $ctx->compiledRelations[$relation];

        $relatedTable = $compiled['relatedTable'];
        $pivotTable = $compiled['kind'] === RelationKind::BelongsToMany ? $compiled['pivotTable'] : null;

        // MySQL forbids the UPDATE/DELETE target table inside a subquery FROM (incl. JOINs).
        if (!$ctx->useAlias && ($relatedTable === $ctx->baseAlias || $pivotTable === $ctx->baseAlias)) {
            throw new \InvalidArgumentException(
                "Self-referential EXISTS on relation '{$relation}' is not supported in "
                . "updateBy/forceDeleteBy: target table '{$ctx->baseAlias}' cannot appear in a subquery FROM."
            );
        }

        $relatedPK = $compiled['relatedPrimaryKey'];
        $relAlias = '_r' . $ctx->counter->next() . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $relation);

        if ($compiled['kind'] === RelationKind::BelongsToMany) {
            $pivotAlias = $relAlias . '_p';
            $from = "{$compiled['pivotTable']} {$pivotAlias} INNER JOIN {$relatedTable} {$relAlias} "
                . "ON {$relAlias}.{$relatedPK} = {$pivotAlias}.{$compiled['relatedPivotKey']}";
            $correlation = "{$pivotAlias}.{$compiled['foreignPivotKey']} = {$ctx->baseAlias}.{$ctx->basePrimaryKey}";
        } else {
            $foreignKey = $compiled['foreignKey'];
            $from = "{$relatedTable} {$relAlias}";
            $correlation = $compiled['kind'] === RelationKind::BelongsTo
                ? "{$relAlias}.{$relatedPK} = {$ctx->baseAlias}.{$foreignKey}"
                : "{$relAlias}.{$foreignKey} = {$ctx->baseAlias}.{$ctx->basePrimaryKey}";
        }

        $translation = $compiled['translation'] ?? null;
        if ($translation !== null && $ctx->currentLocale !== null) {
            $tAlias = $relAlias . '_t';
            $localeParam = '_cb_loc' . $ctx->counter->next();
            $ctx->qb->setParameter($localeParam, $ctx->currentLocale);
            $from .= " LEFT JOIN {$translation['table']} {$tAlias} "
                . "ON {$tAlias}.{$translation['foreignKey']} = {$relAlias}.{$relatedPK} "
                . "AND {$tAlias}.locale = :{$localeParam}";
            $fields = $this->rewriteTranslatedFields($fields, $translation['fields'], $tAlias);
        }

        $body = $this->buildGroup($ctx->forSubquery($relAlias, $relatedPK), $fields, 'AND');
        $where = $body !== null ? "{$correlation} AND {$body}" : $correlation;

        return ($isNotExists ? 'NOT EXISTS' : 'EXISTS') . " (SELECT 1 FROM {$from} WHERE {$where})";
    }

    /**
     * @param array<string|int, mixed> $fields
     * @param list<string>             $translatedFields
     * @return array<string|int, mixed>
     */
    private function rewriteTranslatedFields(array $fields, array $translatedFields, string $tAlias): array
    {
        return $this->rewriteTranslatedRecur($fields, array_flip($translatedFields), $tAlias);
    }

    /**
     * @param array<string|int, mixed> $fields
     * @param array<string, int>       $flipped
     * @return array<string|int, mixed>
     */
    private function rewriteTranslatedRecur(array $fields, array $flipped, string $tAlias): array
    {
        $result = [];
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                $result[$key] = is_array($value) ? $this->rewriteTranslatedRecur($value, $flipped, $tAlias) : $value;
                continue;
            }
            if (($key === 'OR' || $key === 'AND') && is_array($value)) {
                $result[$key] = $this->rewriteTranslatedRecur($value, $flipped, $tAlias);
                continue;
            }
            if (isset($flipped[$key])) {
                $result["{$tAlias}.{$key}"] = $value;
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function assertSafeOperator(string $operator): void
    {
        if (!in_array(strtoupper($operator), self::ALLOWED_OPERATORS, true)) {
            throw new \InvalidArgumentException("Unsafe operator: {$operator}");
        }
    }
}
