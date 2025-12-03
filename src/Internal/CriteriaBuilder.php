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
    private const array ALLOWED_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];

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
            $quotedField = $useAlias ? "{$this->tableAlias}.{$field}" : $field;

            if ($value === null) {
                $qb->andWhere("{$quotedField} IS NULL");
                continue;
            }

            // Check for operator syntax [operator, value] before plain IN list
            if (
                is_array($value)
                && array_key_exists(0, $value)
                && array_key_exists(1, $value)
                && is_string($value[0])
            ) {
                [$operator, $val] = $value;
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
                        ? $qb->expr()->in($quotedField, ':' . $field)
                        : $qb->expr()->notIn($quotedField, ':' . $field);
                    $qb->andWhere($expr);
                    $qb->setParameter($field, $val, $this->determineArrayParamType($val));
                    continue;
                }

                $qb->andWhere("{$quotedField} {$operator} :{$field}");
                $qb->setParameter($field, $val);
                continue;
            }

            // Plain array treated as IN list
            if (is_array($value) && array_is_list($value)) {
                $qb->andWhere($qb->expr()->in($quotedField, ':' . $field));
                $qb->setParameter($field, $value, $this->determineArrayParamType($value));
                continue;
            }

            $qb->andWhere("{$quotedField} = :{$field}");
            $qb->setParameter($field, $value);
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
            $quotedField = "{$this->tableAlias}.{$field}";
            $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $qb->orderBy($quotedField, $dir);
        }
    }

    /**
     * @param string $identifier
     */
    private function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
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
