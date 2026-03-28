<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * @internal
 */
final class TranslationService
{
    private ?string $locale = null;

    /**
     * @param string $table Translation table name
     * @param string $foreignKey Foreign key column in translation table
     * @param list<string> $fields Translated field names
     */
    public function __construct(
        private readonly string $table,
        private readonly string $foreignKey,
        private readonly array $fields,
    ) {
        $this->assertSafeIdentifier($foreignKey);
        foreach ($fields as $field) {
            $this->assertSafeIdentifier($field);
        }
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function hasLocale(): bool
    {
        return $this->locale !== null;
    }

    public function reset(): void
    {
        $this->locale = null;
    }

    /**
     * Apply LEFT JOIN with translation table to the query builder.
     */
    public function applyJoin(QueryBuilder $qb, string $tableAlias, string $primaryKey): void
    {
        if ($this->locale === null) {
            return;
        }

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

        $qb->setParameter('tr_locale', $this->locale);
    }

    private function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Unsafe identifier: {$identifier}");
        }
    }
}
