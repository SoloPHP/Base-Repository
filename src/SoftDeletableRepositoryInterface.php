<?php

declare(strict_types=1);

namespace Solo\BaseRepository;

/**
 * @template TModel of object
 * @extends RepositoryInterface<TModel>
 */
interface SoftDeletableRepositoryInterface extends RepositoryInterface
{
    /**
     * @return SoftDeletableRepositoryInterface<TModel>
     */
    public function withTrashed(): self;

    /**
     * @return int affected rows
     */
    public function restoreById(int|string $id): int;

    public function forceDeleteById(int|string $id): int;

    /**
     * @param array<string, mixed> $criteria
     */
    public function forceDeleteBy(array $criteria): int;
}
