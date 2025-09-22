<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

class SoftDeleteService
{
    public function __construct(
        private string $deletedAtColumn = 'deleted_at'
    ) {
    }

    /**
     * Apply soft delete criteria to existing criteria array
     */
    public function applyCriteria(array $criteria): array
    {
        if (isset($criteria['deleted'])) {
            return $criteria;
        }

        $criteria['deleted'] = 'without';
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

    /**
     * Get deleted at column name
     */
    public function getDeletedAtColumn(): string
    {
        return $this->deletedAtColumn;
    }

    private function getCurrentTimestamp(): string
    {
        return (new \DateTime())->format('Y-m-d H:i:s');
    }
}
