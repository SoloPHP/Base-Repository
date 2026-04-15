<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\ArrayParameterType;

/**
 * @internal
 */
final readonly class CriteriaBuilder
{
    private const array ALLOWED_OPERATORS = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN',
    ];

    /**
     * @param non-empty-string $tableAlias
     */
    public function __construct(
        private string $tableAlias
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     * @return QueryBuilder
     */
    public function applyCriteria(QueryBuilder $qb, array $criteria, bool $useAlias = true): QueryBuilder
    {
        foreach ($criteria as $field => $value) {
            $this->assertSafeIdentifier($field);
            $isQualified = str_contains($field, '.');
            $quotedField = ($useAlias && !$isQualified) ? "{$this->tableAlias}.{$field}" : $field;
            $paramName = $isQualified ? str_replace('.', '_', $field) : $field;

            if ($value === null) {
                $qb->andWhere("{$quotedField} IS NULL");
                continue;
            }

            if (is_array($value)) {
                if (is_int(array_key_first($value))) {
                    // Integer-keyed array = IN list: [1, 2, 3] or [0 => 5, 2 => 8]
                    $reindexed = array_values($value);
                    $qb->andWhere($qb->expr()->in($quotedField, ':' . $paramName));
                    $qb->setParameter($paramName, $reindexed, $this->determineArrayParamType($reindexed));
                    continue;
                }

                // Associative array = operator syntax: ['!=' => 'value']
                $operator = (string) array_key_first($value);
                $val = $value[$operator];
                $this->assertSafeOperator($operator);
                $upperOp = strtoupper($operator);

                if ($val === null) {
                    if ($upperOp === '=') {
                        $qb->andWhere("{$quotedField} IS NULL");
                        continue;
                    }
                    if ($upperOp === '!=' || $upperOp === '<>') {
                        $qb->andWhere("{$quotedField} IS NOT NULL");
                        continue;
                    }
                }

                if ($upperOp === 'IN' || $upperOp === 'NOT IN') {
                    if (!is_array($val)) {
                        $val = [$val];
                    }
                    $expr = $upperOp === 'IN'
                        ? $qb->expr()->in($quotedField, ':' . $paramName)
                        : $qb->expr()->notIn($quotedField, ':' . $paramName);
                    $qb->andWhere($expr);
                    $qb->setParameter($paramName, $val, $this->determineArrayParamType($val));
                    continue;
                }

                if ($upperOp === 'BETWEEN') {
                    if (!is_array($val) || count($val) !== 2) {
                        throw new \InvalidArgumentException("BETWEEN requires array of exactly 2 values");
                    }
                    $qb->andWhere("{$quotedField} BETWEEN :{$paramName}_min AND :{$paramName}_max");
                    $qb->setParameter("{$paramName}_min", $val[0]);
                    $qb->setParameter("{$paramName}_max", $val[1]);
                    continue;
                }

                $qb->andWhere("{$quotedField} {$operator} :{$paramName}");
                $qb->setParameter($paramName, $val);
                continue;
            }

            $qb->andWhere("{$quotedField} = :{$paramName}");
            $qb->setParameter($paramName, $value);
        }

        return $qb;
    }

    /**
     * @param array<string, 'ASC'|'DESC'> $orderBy
     */
    public function applyOrderBy(QueryBuilder $qb, array $orderBy): void
    {
        foreach ($orderBy as $field => $direction) {
            $this->assertSafeIdentifier($field);
            $quotedField = str_contains($field, '.') ? $field : "{$this->tableAlias}.{$field}";
            $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $qb->addOrderBy($quotedField, $dir);
        }
    }

    /**
     * @param string $identifier
     */
    private function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $identifier)) {
            throw new \InvalidArgumentException("Unsafe identifier: {$identifier}");
        }
    }

    /**
     * @param string $operator
     */
    private function assertSafeOperator(string $operator): void
    {
        if (!in_array(strtoupper($operator), self::ALLOWED_OPERATORS, true)) {
            throw new \InvalidArgumentException("Unsafe operator: {$operator}");
        }
    }

    private function determineArrayParamType(array $values): ArrayParameterType
    {
        foreach ($values as $v) {
            if (!is_int($v)) {
                return ArrayParameterType::STRING;
            }
        }
        return ArrayParameterType::INTEGER;
    }
}
