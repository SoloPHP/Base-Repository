<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Stateless translation joiner. The active locale lives on BaseRepository
 * and is passed in at the call site.
 *
 * @internal
 */
final readonly class TranslationService
{
    /**
     * @param string       $table      Translation table name
     * @param string       $foreignKey Foreign key column in translation table
     * @param list<string> $fields     Translated field names
     */
    public function __construct(
        private string $table,
        private string $foreignKey,
        private array $fields,
    ) {
        $this->assertSafeIdentifier($foreignKey);
        foreach ($fields as $field) {
            $this->assertSafeIdentifier($field);
        }
    }

    /**
     * Apply LEFT JOIN with translation table to the query builder.
     */
    public function applyJoin(QueryBuilder $qb, string $tableAlias, string $primaryKey, string $locale): void
    {
        $qb->leftJoin(
            $tableAlias,
            $this->table,
            'tr',
            sprintf(
                'tr.%s = %s.%s AND tr.locale = :tr_locale',
                $this->foreignKey,
                $tableAlias,
                $primaryKey
            )
        );

        foreach ($this->fields as $field) {
            $qb->addSelect('tr.' . $field);
        }

        $qb->setParameter('tr_locale', $locale);
    }

    private function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Unsafe identifier: {$identifier}");
        }
    }
}
