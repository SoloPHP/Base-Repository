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
                if ($field !== '' && isset($relationConfig[$relation])) {
                    $relationCriteria[$relation][$field] = $value;
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
            $compiled = $compiledRelations[$relation] ?? null;
            if ($compiled === null) {
                continue;
            }

            $type = $compiled['type'];
            $foreignKey = $compiled['foreignKey'];
            $relatedTable = $compiled['relatedTable'];
            $relatedPrimaryKey = $compiled['relatedPrimaryKey'];

            $alias = 'rel_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string) $relation);

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

                $paramName = 'rel_' . $relation . '_' . $field . '_' . (++$paramIndex);

                if ($val === null) {
                    $sub->andWhere("{$alias}.{$field} IS NULL");
                    continue;
                }

                if (is_array($val) && array_is_list($val)) {
                    if ($val === []) {
                        $sub->andWhere('1 = 0');
                        continue;
                    }
                    $sub->andWhere($sub->expr()->in("{$alias}.{$field}", ':' . $paramName));
                    $qb->setParameter($paramName, $val, $this->determineArrayParamType($val));
                    continue;
                }

                if (is_array($val) && isset($val[0], $val[1]) && is_string($val[0])) {
                    [$operator, $value] = $val;
                    $this->assertSafeOperator($operator);
                    $sub->andWhere("{$alias}.{$field} {$operator} :{$paramName}");
                    $qb->setParameter($paramName, $value);
                    continue;
                }

                $sub->andWhere("{$alias}.{$field} = :{$paramName}");
                $qb->setParameter($paramName, $val);
            }

            $qb->andWhere('EXISTS (' . $sub->getSQL() . ')');
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
