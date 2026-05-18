<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

/**
 * Shared mutable counter used by CriteriaBuilder to mint unique parameter
 * and alias names across recursive build calls.
 *
 * @internal
 */
final class CompileCounter
{
    public int $n = 0;

    public function next(): int
    {
        return ++$this->n;
    }
}
