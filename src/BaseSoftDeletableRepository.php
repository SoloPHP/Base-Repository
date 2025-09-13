<?php

declare(strict_types=1);

namespace Solo\BaseRepository;

use Doctrine\DBAL\Connection;

/**
 * @template TModel of object
 * @extends BaseRepository<TModel>
 * @implements SoftDeletableRepositoryInterface<TModel>
 */
abstract class BaseSoftDeletableRepository extends BaseRepository implements SoftDeletableRepositoryInterface
{
    private bool $withTrashedMode = false;
    /** @var non-empty-string */
    protected string $deletedAtColumn = 'deleted_at';

    /**
     * @return $this
     */
    public function withTrashed(): self
    {
        $this->withTrashedMode = true;
        return $this;
    }

    /**
     * @return non-empty-string
     */
    protected function getCurrentTimestamp(): string
    {
        return (new \DateTime())->format('Y-m-d H:i:s');
    }

    /**
     * @return non-empty-string
     */
    protected function getDeletedAtColumn(): string
    {
        return $this->deletedAtColumn;
    }

    /**
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    private function executeWithTrashedMode(callable $callback): mixed
    {
        try {
            return $callback();
        } finally {
            $this->withTrashedMode = false;
        }
    }

    /**
     * @return list<TModel>
     */
    public function getAll(): array
    {
        if ($this->withTrashedMode) {
            return $this->executeWithTrashedMode(fn() => parent::getAll());
        }
        return $this->getBy(['deleted' => 'without']);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'>|null $orderBy
     * @return list<TModel>
     */
    public function getBy(array $criteria, ?array $orderBy = null, ?int $perPage = null, ?int $page = null): array
    {
        if ($this->withTrashedMode) {
            return $this->executeWithTrashedMode(function () use ($criteria, $orderBy, $perPage, $page) {
                return parent::getBy($criteria, $orderBy, $perPage, $page);
            });
        }
        if (!isset($criteria['deleted'])) {
            $criteria['deleted'] = 'without';
        }
        return parent::getBy($criteria, $orderBy, $perPage, $page);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'>|null $orderBy
     * @return TModel|null
     */
    public function getFirstBy(array $criteria, ?array $orderBy = null): ?object
    {
        if ($this->withTrashedMode) {
            return $this->executeWithTrashedMode(function () use ($criteria, $orderBy) {
                return parent::getFirstBy($criteria, $orderBy);
            });
        }
        if (!isset($criteria['deleted'])) {
            $criteria['deleted'] = 'without';
        }
        return parent::getFirstBy($criteria, $orderBy);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function existsBy(array $criteria): bool
    {
        if ($this->withTrashedMode) {
            return $this->executeWithTrashedMode(function () use ($criteria) {
                return parent::existsBy($criteria);
            });
        }
        if (!isset($criteria['deleted'])) {
            $criteria['deleted'] = 'without';
        }
        return parent::existsBy($criteria);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function countBy(array $criteria): int
    {
        if ($this->withTrashedMode) {
            return $this->executeWithTrashedMode(function () use ($criteria) {
                return parent::countBy($criteria);
            });
        }
        if (!isset($criteria['deleted'])) {
            $criteria['deleted'] = 'without';
        }
        return parent::countBy($criteria);
    }

    /**
     * @return int affected rows
     */
    public function deleteById(int|string $id): int
    {
        return $this->update($id, [$this->getDeletedAtColumn() => $this->getCurrentTimestamp()]);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return int affected rows
     */
    public function deleteBy(array $criteria): int
    {
        return $this->updateBy($criteria, [$this->getDeletedAtColumn() => $this->getCurrentTimestamp()]);
    }

    public function forceDeleteById(int|string $id): int
    {
        return parent::deleteById($id);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function forceDeleteBy(array $criteria): int
    {
        return parent::deleteBy($criteria);
    }

    public function restoreById(int|string $id): int
    {
        return $this->update($id, [$this->getDeletedAtColumn() => null]);
    }
}
