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
    private const array ALLOWED_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];

    public function __construct(private Connection $connection)
    {
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
     *     relatedPrimaryKey: string,
     *     pivotTable?: string,
     *     foreignPivotKey?: string,
     *     relatedPivotKey?: string
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
            $relatedTable = $compiled['relatedTable'];
            $relatedPrimaryKey = $compiled['relatedPrimaryKey'];

            $alias = 'rel_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string) $actualRelation);

            if ($type === 'belongsToMany') {
                // For belongsToMany, we need to join through the pivot table
                $pivotTable = $compiled['pivotTable'];
                $foreignPivotKey = $compiled['foreignPivotKey'];
                $relatedPivotKey = $compiled['relatedPivotKey'];

                $pivotAlias = 'pivot_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string) $actualRelation);

                $sub = $this->connection->createQueryBuilder()
                    ->select('1')
                    ->from($pivotTable, $pivotAlias)
                    ->innerJoin(
                        $pivotAlias,
                        $relatedTable,
                        $alias,
                        "{$alias}.{$relatedPrimaryKey} = {$pivotAlias}.{$relatedPivotKey}"
                    )
                    ->andWhere("{$pivotAlias}.{$foreignPivotKey} = {$baseAlias}.{$basePrimaryKey}");
            } else {
                $foreignKey = $compiled['foreignKey'];

                $sub = $this->connection->createQueryBuilder()
                    ->select('1')
                    ->from($relatedTable, $alias);

                if ($type === 'hasMany' || $type === 'hasOne') {
                    $sub->andWhere("{$alias}.{$foreignKey} = {$baseAlias}.{$basePrimaryKey}");
                } elseif ($type === 'belongsTo') {
                    $sub->andWhere("{$alias}.{$relatedPrimaryKey} = {$baseAlias}.{$foreignKey}");
                } else {
                    continue;
                }
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

                if (is_array($val)) {
                    if (array_is_list($val)) {
                        // Sequential array = IN list: ['active', 'pending']
                        if ($val === []) {
                            $sub->andWhere('1 = 0');
                            continue;
                        }
                        $sub->andWhere($sub->expr()->in("{$alias}.{$field}", ':' . $paramName));
                        $qb->setParameter($paramName, $val, $this->determineArrayParamType($val));
                        continue;
                    }

                    // Associative array = operator syntax: ['!=' => 'value']
                    $operator = (string) array_key_first($val);
                    $value = $val[$operator];
                    $this->assertSafeOperator($operator);
                    $upperOp = strtoupper($operator);

                    if ($value === null) {
                        if ($upperOp === '=') {
                            $sub->andWhere("{$alias}.{$field} IS NULL");
                            continue;
                        }
                        if ($upperOp === '!=' || $upperOp === '<>') {
                            $sub->andWhere("{$alias}.{$field} IS NOT NULL");
                            continue;
                        }
                    }

                    if ($upperOp === 'IN' || $upperOp === 'NOT IN') {
                        if (!is_array($value)) {
                            $value = [$value];
                        }
                        if ($value === []) {
                            $sub->andWhere($upperOp === 'IN' ? '1 = 0' : '1 = 1');
                            continue;
                        }
                        $expr = $upperOp === 'IN'
                            ? $sub->expr()->in("{$alias}.{$field}", ':' . $paramName)
                            : $sub->expr()->notIn("{$alias}.{$field}", ':' . $paramName);
                        $sub->andWhere($expr);
                        $qb->setParameter($paramName, $value, $this->determineArrayParamType($value));
                        continue;
                    }

                    $sub->andWhere("{$alias}.{$field} {$operator} :{$paramName}");
                    $qb->setParameter($paramName, $value);
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
        if (!in_array(strtoupper($operator), self::ALLOWED_OPERATORS, true)) {
            throw new \InvalidArgumentException("Unsafe operator: {$operator}");
        }
    }
}
