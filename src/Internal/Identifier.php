<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

/**
 * @internal
 */
final class Identifier
{
    public static function assertSafe(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Unsafe identifier: {$identifier}");
        }
    }

    /**
     * Criteria-flavored: also allows '!' prefix (NOT EXISTS) and '.' (relation dot-notation).
     */
    public static function assertSafeCriteriaKey(string $identifier): void
    {
        if (!preg_match('/^!?[A-Za-z_][A-Za-z0-9_.]*$/', $identifier)) {
            throw new \InvalidArgumentException("Unsafe identifier: {$identifier}");
        }
        if (str_starts_with($identifier, '!') && !str_contains($identifier, '.')) {
            throw new \InvalidArgumentException(
                "'!' prefix is only valid on relation dot-notation (e.g. '!relation.field'): {$identifier}"
            );
        }
    }
}
