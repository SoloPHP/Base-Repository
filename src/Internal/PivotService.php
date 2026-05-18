<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

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

    /**
     * @param list<int|string>      $relatedIds
     * @param list<int|string>|null $existing  Pass to skip the membership SELECT (sync() uses this).
     */
    public function attach(
        BelongsToMany $config,
        int|string $id,
        array $relatedIds,
        ?array $existing = null,
    ): void {
        if ($relatedIds === []) {
            return;
        }

        $existing ??= $this->fetchExistingRelatedIds($config, $id);
        $toInsert = array_values(array_filter(
            $relatedIds,
            static fn($r) => !in_array($r, $existing, false),
        ));

        if ($toInsert === []) {
            return;
        }

        Identifier::assertSafe($config->pivot);
        Identifier::assertSafe($config->foreignPivotKey);
        Identifier::assertSafe($config->relatedPivotKey);

        $placeholders = implode(', ', array_fill(0, count($toInsert), '(?, ?)'));
        $params = [];
        foreach ($toInsert as $relatedId) {
            $params[] = $id;
            $params[] = $relatedId;
        }

        $this->connection->executeStatement(
            "INSERT INTO {$config->pivot} ({$config->foreignPivotKey}, {$config->relatedPivotKey})"
            . " VALUES {$placeholders}",
            $params,
        );
    }

    /**
     * @param list<int|string> $relatedIds
     */
    public function detach(BelongsToMany $config, int|string $id, array $relatedIds = []): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->delete($config->pivot)
            ->where("{$config->foreignPivotKey} = :id")
            ->setParameter('id', $id);

        if ($relatedIds !== []) {
            $qb->andWhere("{$config->relatedPivotKey} IN (:relatedIds)")
                ->setParameter('relatedIds', $relatedIds, Identifier::arrayParamTypeFor($relatedIds));
        }

        $qb->executeStatement();
    }

    /**
     * @param list<int|string> $relatedIds
     */
    public function sync(BelongsToMany $config, int|string $id, array $relatedIds): void
    {
        $existing = $this->fetchExistingRelatedIds($config, $id);

        $toDelete = array_values(array_diff($existing, $relatedIds));
        if ($toDelete !== []) {
            $this->detach($config, $id, $toDelete);
        }

        $this->attach($config, $id, $relatedIds, $existing);
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
