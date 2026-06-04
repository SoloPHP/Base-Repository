<?php

declare(strict_types=1);

namespace Solo\BaseRepository;

/**
 * Thrown by withLock() when an advisory lock cannot be acquired within the
 * given timeout. Extends RuntimeException so callers can either catch it
 * specifically (e.g. to re-queue a job) or treat it as a generic runtime error.
 */
final class LockTimeoutException extends \RuntimeException
{
}
