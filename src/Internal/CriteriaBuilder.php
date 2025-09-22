<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\ArrayParameterType;

/**
 * @internal
 */
class CriteriaBuilder
{
    /**
     * @param non-empty-string $tableAlias
     * @param non-empty-string $deletedAtColumn
     */
    public function __construct(
        private string $tableAlias,
        private string $deletedAtColumn = 'deleted_at'
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     * @return QueryBuilder
     */
    public function applyCriteria(QueryBuilder $qb, array $criteria, bool $useAlias = true): QueryBuilder
    {
        foreach ($criteria as $field => $value) {
            if ($field === 'search' && is_array($value)) {
                $this->applySearchConditions($qb, $value);
                continue;
            }

            if ($field === 'deleted') {
                $mode = (is_string($value) && in_array($value, ['only','with','without'], true)) ? $value : null;
                $this->applyDeletedCondition($qb, $mode, $useAlias);
                continue;
            }

            $this->assertSafeIdentifier($field);
            $quotedField = $useAlias ? "{$this->tableAlias}.{$field}" : $field;

            if ($value === null) {
                $qb->andWhere("{$quotedField} IS NULL");
                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                $qb->andWhere($qb->expr()->in($quotedField, ':' . $field));
                $paramType = ArrayParameterType::STRING;
                if (!empty($value) && is_int($value[0])) {
                    $paramType = ArrayParameterType::INTEGER;
                }
                $qb->setParameter($field, $value, $paramType);
                continue;
            }

            if (is_array($value) && isset($value[0], $value[1]) && is_string($value[0])) {
                [$operator, $val] = $value;
                $this->assertSafeOperator($operator);
                $qb->andWhere("{$quotedField} {$operator} :{$field}");
                $qb->setParameter($field, $val);
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
     * @param array<mixed, mixed> $search
     */
    private function applySearchConditions(QueryBuilder $qb, array $search): void
    {
        if ($search === []) {
            return;
        }

        foreach ($search as $searchField => $searchValue) {
            if ($searchValue !== null && $searchValue !== '') {
                $this->assertSafeIdentifier($searchField);
                $quotedField = "{$this->tableAlias}.{$searchField}";
                $paramName = 'search_' . $searchField;
                $qb->andWhere("{$quotedField} LIKE :{$paramName}");
                $valueAsString = is_scalar($searchValue) ? (string) $searchValue : '';
                $qb->setParameter($paramName, '%' . $valueAsString . '%');
            }
        }
    }

    /**
     * @param 'only'|'with'|'without'|null $deletedMode
     */
    private function applyDeletedCondition(QueryBuilder $qb, ?string $deletedMode, bool $useAlias): void
    {
        $fullColumn = $useAlias ? "{$this->tableAlias}.{$this->deletedAtColumn}" : $this->deletedAtColumn;

        if ($deletedMode === 'only') {
            $qb->andWhere("{$fullColumn} IS NOT NULL");
        } elseif ($deletedMode !== 'with') {
            $qb->andWhere("{$fullColumn} IS NULL");
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
        $allowedOperators = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];

        if (!in_array(strtoupper($operator), $allowedOperators, true)) {
            throw new \InvalidArgumentException("Unsafe operator: {$operator}");
        }
    }
}
