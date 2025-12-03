<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

final readonly class SoftDeleteService
{
    public function __construct(
        private string $deletedAtColumn = 'deleted_at'
    ) {
    }

    /**
     * Apply soft delete criteria to existing criteria array
     *
     * Special value '*' means "show all" (no filter applied)
     */
    public function applyCriteria(array $criteria): array
    {
        if (isset($criteria[$this->deletedAtColumn])) {
            // '*' means show all - remove the key entirely
            if ($criteria[$this->deletedAtColumn] === '*') {
                unset($criteria[$this->deletedAtColumn]);
            }
            return $criteria;
        }

        $criteria[$this->deletedAtColumn] = null;
        return $criteria;
    }

    /**
     * Get data for soft delete operation
     */
    public function getSoftDeleteData(): array
    {
        return [$this->deletedAtColumn => $this->getCurrentTimestamp()];
    }

    /**
     * Get data for restore operation
     */
    public function getRestoreData(): array
    {
        return [$this->deletedAtColumn => null];
    }

    private function getCurrentTimestamp(): string
    {
        return (new \DateTime())->format('Y-m-d H:i:s');
    }
}
