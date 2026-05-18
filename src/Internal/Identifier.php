<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\ArrayParameterType;

/**
 * @internal
 */
final class Identifier
{
    /**
     * @param array<int, mixed> $values
     */
    public static function arrayParamTypeFor(array $values): ArrayParameterType
    {
        foreach ($values as $v) {
            if (!is_int($v)) {
                return ArrayParameterType::STRING;
            }
        }
        return ArrayParameterType::INTEGER;
    }


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
