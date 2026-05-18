<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

/**
 * @internal
 * @template TModel of object
 */
final class ModelMapper
{
    /** @var (\Closure(array<string, mixed>): TModel)|null */
    private ?\Closure $mapper = null;

    /**
     * @param class-string<TModel> $modelClass
     * @param non-empty-string $mapperMethod
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly string $mapperMethod = 'fromArray',
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return TModel
     */
    public function map(array $row): object
    {
        if ($this->mapper === null) {
            if (!is_callable([$this->modelClass, $this->mapperMethod])) {
                throw new \RuntimeException("Mapper {$this->modelClass}::{$this->mapperMethod} not callable");
            }
            /** @var \Closure(array<string, mixed>): TModel $closure */
            $closure = \Closure::fromCallable([$this->modelClass, $this->mapperMethod]);
            $this->mapper = $closure;
        }

        return ($this->mapper)($row);
    }
}
