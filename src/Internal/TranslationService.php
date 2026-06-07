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
        Identifier::assertSafe($foreignKey);
        foreach ($fields as $field) {
            Identifier::assertSafe($field);
        }
    }

    /**
     * Apply LEFT JOIN with translation table to the query builder.
     *
     * When $fallbackLocale is given and differs from $locale, a second JOIN on the
     * fallback locale is added and each field is COALESCE'd — a value empty or
     * missing in the primary locale falls back to it, so callers never get a NULL
     * where the fallback has a value.
     */
    public function applyJoin(
        QueryBuilder $qb,
        string $tableAlias,
        string $primaryKey,
        string $locale,
        ?string $fallbackLocale = null,
    ): void {
        $useFallback = $fallbackLocale !== null && $fallbackLocale !== $locale;

        $qb->leftJoin(
            $tableAlias,
            $this->table,
            'tr',
            $this->joinOn('tr', $tableAlias, $primaryKey, 'tr_locale')
        );
        $qb->setParameter('tr_locale', $locale);

        if ($useFallback) {
            $qb->leftJoin(
                $tableAlias,
                $this->table,
                'tr_fb',
                $this->joinOn('tr_fb', $tableAlias, $primaryKey, 'tr_fb_locale')
            );
            $qb->setParameter('tr_fb_locale', $fallbackLocale);
        }

        foreach ($this->fields as $field) {
            $qb->addSelect(
                $useFallback
                    ? sprintf("COALESCE(NULLIF(tr.%s, ''), tr_fb.%s) AS %s", $field, $field, $field)
                    : 'tr.' . $field
            );
        }
    }

    /**
     * Build the ON condition for a translation LEFT JOIN under the given alias.
     */
    private function joinOn(string $alias, string $tableAlias, string $primaryKey, string $localeParam): string
    {
        return sprintf(
            '%s.%s = %s.%s AND %s.locale = :%s',
            $alias,
            $this->foreignKey,
            $tableAlias,
            $primaryKey,
            $alias,
            $localeParam
        );
    }
}
