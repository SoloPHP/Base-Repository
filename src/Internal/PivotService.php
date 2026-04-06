<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Solo\BaseRepository\Relation\BelongsToMany;

/**
 * @internal
 */
final readonly class PivotService
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function attach(BelongsToMany $config, int|string $id, array $relatedIds): void
    {
        if (empty($relatedIds)) {
            return;
        }

        $existing = $this->fetchExistingRelatedIds($config, $id);

        foreach ($relatedIds as $relatedId) {
            if (in_array($relatedId, $existing, false)) {
                continue;
            }

            $this->connection->insert($config->pivot, [
                $config->foreignPivotKey => $id,
                $config->relatedPivotKey => $relatedId,
            ]);
        }
    }

    public function detach(BelongsToMany $config, int|string $id, array $relatedIds = []): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->delete($config->pivot)
            ->where("{$config->foreignPivotKey} = :id")
            ->setParameter('id', $id);

        if (!empty($relatedIds)) {
            $qb->andWhere("{$config->relatedPivotKey} IN (:relatedIds)")
                ->setParameter('relatedIds', $relatedIds, ArrayParameterType::STRING);
        }

        $qb->executeStatement();
    }

    public function sync(BelongsToMany $config, int|string $id, array $relatedIds): void
    {
        $existing = $this->fetchExistingRelatedIds($config, $id);

        $toInsert = array_diff($relatedIds, $existing);
        $toDelete = array_diff($existing, $relatedIds);

        if (!empty($toDelete)) {
            $this->detach($config, $id, $toDelete);
        }

        if (!empty($toInsert)) {
            $this->attach($config, $id, $toInsert);
        }
    }

    /**
     * @return list<int|string>
     */
    private function fetchExistingRelatedIds(BelongsToMany $config, int|string $id): array
    {
        return $this->connection->createQueryBuilder()
            ->select($config->relatedPivotKey)
            ->from($config->pivot)
            ->where("{$config->foreignPivotKey} = :id")
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchFirstColumn();
    }
}
