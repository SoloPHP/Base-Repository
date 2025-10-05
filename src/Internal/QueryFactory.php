<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * @internal
 */
final readonly class QueryFactory
{
    public function __construct(
        private Connection $connection,
        private string $table,
        private string $tableAlias
    ) {
    }

    public function builder(): QueryBuilder
    {
        return $this->connection->createQueryBuilder();
    }

    public function tableSelectAll(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table, $this->tableAlias);
    }

    public function insertBuilder(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()->insert($this->table);
    }

    public function updateByIdBuilder(string $primaryKey, int|string $id): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->update($this->table)
            ->andWhere("{$primaryKey} = :id")
            ->setParameter('id', $id);
    }

    public function deleteByIdBuilder(string $primaryKey, int|string $id): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->delete($this->table)
            ->andWhere("{$primaryKey} = :id")
            ->setParameter('id', $id);
    }
}
