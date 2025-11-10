<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * @internal
 */
final readonly class RelationCriteriaApplier
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Split criteria into base-table and relation (dot-notation) criteria.
     *
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $relationConfig
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    public function splitCriteria(array $criteria, array $relationConfig): array
    {
        $baseCriteria = [];
        $relationCriteria = [];

        foreach ($criteria as $key => $value) {
            if (str_contains($key, '.')) {
                [$relation, $field] = explode('.', $key, 2);

                // Check for NOT EXISTS prefix (!)
                $isNotExists = false;
                if (str_starts_with($relation, '!')) {
                    $isNotExists = true;
                    $relation = substr($relation, 1);
                }

                if ($field !== '' && isset($relationConfig[$relation])) {
                    // Store with ! prefix if NOT EXISTS
                    $relationKey = $isNotExists ? '!' . $relation : $relation;
                    $relationCriteria[$relationKey][$field] = $value;
                    continue;
                }
            }
            $baseCriteria[$key] = $value;
        }

        return [$baseCriteria, $relationCriteria];
    }

    /**
     * Apply relation criteria using EXISTS subqueries.
     *
     * @param array $compiledRelations Compiled relations metadata.
     * @param array<string, array<string, mixed>> $relationCriteria
     *
     * Expected shape of $compiledRelations:
     * - key: relation name (string)
     * - value: array{
     *     type: string,
     *     foreignKey: string,
     *     relatedTable: string,
     *     relatedPrimaryKey: string
     *   }
     */
    public function apply(
        QueryBuilder $qb,
        string $baseAlias,
        string $basePrimaryKey,
        array $compiledRelations,
        array $relationCriteria
    ): void {
        $paramIndex = 0;

        foreach ($relationCriteria as $relation => $fields) {
            // Check for NOT EXISTS prefix (!)
            $isNotExists = false;
            $actualRelation = $relation;
            if (str_starts_with($relation, '!')) {
                $isNotExists = true;
                $actualRelation = substr($relation, 1);
            }

            $compiled = $compiledRelations[$actualRelation] ?? null;
            if ($compiled === null) {
                continue;
            }

            $type = $compiled['type'];
            $foreignKey = $compiled['foreignKey'];
            $relatedTable = $compiled['relatedTable'];
            $relatedPrimaryKey = $compiled['relatedPrimaryKey'];

            $alias = 'rel_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string) $actualRelation);

            $sub = $this->connection->createQueryBuilder()
                ->select('1')
                ->from($relatedTable, $alias);

            if ($type === 'hasMany') {
                $sub->andWhere("{$alias}.{$foreignKey} = {$baseAlias}.{$basePrimaryKey}");
            } elseif ($type === 'belongsTo') {
                $sub->andWhere("{$alias}.{$relatedPrimaryKey} = {$baseAlias}.{$foreignKey}");
            } else {
                continue;
            }

            foreach ($fields as $field => $val) {
                if ($field === '') {
                    continue;
                }

                $paramName = 'rel_' . $actualRelation . '_' . $field . '_' . (++$paramIndex);

                if ($val === null) {
                    $sub->andWhere("{$alias}.{$field} IS NULL");
                    continue;
                }

                // Проверяем на оператор РАНЬШЕ, чем на список для IN
                if (is_array($val) && array_key_exists(0, $val) && array_key_exists(1, $val) && is_string($val[0])) {
                    [$operator, $value] = $val;
                    $this->assertSafeOperator($operator);
                    if ($value === null) {
                        $upperOp = strtoupper($operator);
                        if ($upperOp === '=') {
                            $sub->andWhere("{$alias}.{$field} IS NULL");
                            continue;
                        }
                        if ($upperOp === '!=' || $upperOp === '<>') {
                            $sub->andWhere("{$alias}.{$field} IS NOT NULL");
                            continue;
                        }
                    }

                    $sub->andWhere("{$alias}.{$field} {$operator} :{$paramName}");
                    $qb->setParameter($paramName, $value);
                    continue;
                }

                // Проверяем на список для IN ПОСЛЕ проверки на оператор
                if (is_array($val) && array_is_list($val)) {
                    if ($val === []) {
                        $sub->andWhere('1 = 0');
                        continue;
                    }
                    $sub->andWhere($sub->expr()->in("{$alias}.{$field}", ':' . $paramName));
                    $qb->setParameter($paramName, $val, $this->determineArrayParamType($val));
                    continue;
                }

                $sub->andWhere("{$alias}.{$field} = :{$paramName}");
                $qb->setParameter($paramName, $val);
            }

            $existsClause = $isNotExists ? 'NOT EXISTS' : 'EXISTS';
            $qb->andWhere("{$existsClause} (" . $sub->getSQL() . ')');
        }
    }

    /**
     * Decide parameter type for IN (...) arrays: INTEGER only if all values are integers, otherwise STRING.
     */
    private function determineArrayParamType(array $values): ArrayParameterType
    {
        foreach ($values as $v) {
            if (!is_int($v)) {
                return ArrayParameterType::STRING;
            }
        }
        return ArrayParameterType::INTEGER;
    }

    private function assertSafeOperator(string $operator): void
    {
        $allowedOperators = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];
        if (!in_array(strtoupper($operator), $allowedOperators, true)) {
            throw new \InvalidArgumentException("Unsafe operator: {$operator}");
        }
    }
}
