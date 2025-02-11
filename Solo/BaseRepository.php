<?php declare(strict_types=1);

namespace Solo;

use Solo\BaseRepository\RepositoryInterface;

abstract class BaseRepository implements RepositoryInterface
{
    protected QueryBuilder $queryBuilder;

    public function __construct(
        protected Database $db,
        protected string   $table,
        protected ?string  $alias = null,
        protected string   $primaryKey = 'id'
    )
    {
        $this->queryBuilder = new QueryBuilder($this->db, $this->table, $this->alias);
    }

    public function findAll(): array
    {
        return $this->queryBuilder->select()->get();
    }

    public function findById(int|string $id): ?array
    {
        return $this->queryBuilder->select()->where($this->primaryKey, '=', $id)->getOne() ?: null;
    }

    public function findByIds(array $ids): array
    {
        return $this->queryBuilder->select()->whereIn($this->primaryKey, $ids)->get();
    }

    public function findBy(array $criteria): array
    {
        $query = $this->queryBuilder->select();
        foreach ($criteria as $key => $value) {
            $query->where($key, '=', $value);
        }
        return $query->get();
    }

    public function findOneBy(array $criteria): ?array
    {
        $query = $this->queryBuilder->select();
        foreach ($criteria as $key => $value) {
            $query->where($key, '=', $value);
        }
        return $query->getOne() ?: null;
    }

    public function findByLike(string|array $fields, string $pattern): array
    {
        $query = $this->queryBuilder->select();
        $query->whereGroup(function ($qb) use ($fields, $pattern) {
        foreach ((array)$fields as $field) {
                $qb->where($field, 'LIKE', "%$pattern%", 'OR');
        }
        });
        return $query->get();
    }

    public function countBy(array $criteria = []): int
    {
        $query = $this->queryBuilder->select(['COUNT(*) as count']);
        foreach ($criteria as $key => $value) {
            $query->where($key, '=', $value);
        }
        return (int)($query->getOne()['count'] ?? 0);
    }

    public function paginate(int $page = 1, int $limit = 10): array
    {
        return $this->queryBuilder->select()->paginate($page, $limit)->get();
    }

    public function create(array $data): bool
    {
        return $this->queryBuilder->insert($data);
    }

    public function bulkInsert(array $records): bool
    {
        if (empty($records)) {
            return false;
        }

        return $this->db->query(
                "INSERT INTO ?t (?p) VALUES ?p",
                $this->table,
                implode(',', array_keys($records[0])),
                array_map('array_values', $records)
            )->rowCount() > 0;
    }

    public function update(int|string $id, array $data): bool
    {
        return $this->queryBuilder->update($data, $this->primaryKey, $id);
    }

    public function delete(int|string $id): bool
    {
        return $this->queryBuilder->delete($this->primaryKey, $id);
    }

    public function count(): int
    {
        return $this->queryBuilder->count();
    }

    public function exists(array $criteria): bool
    {
        return $this->findOneBy($criteria) !== null;
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        $this->db->rollback();
    }
}