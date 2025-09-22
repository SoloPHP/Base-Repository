<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Internal;

/**
 * @internal
 * @template TModel of object
 */
class ModelMapper
{
    /**
     * @param class-string<TModel> $modelClass
     * @param non-empty-string $mapperMethod
     */
    public function __construct(
        private string $modelClass,
        private string $mapperMethod = 'fromArray'
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return TModel
     */
    public function map(array $row): object
    {
        if (!is_callable([$this->modelClass, $this->mapperMethod])) {
            throw new \RuntimeException("Mapper {$this->modelClass}::{$this->mapperMethod} not callable");
        }

        /** @var TModel $obj */
        $obj = ($this->modelClass)::{$this->mapperMethod}($row);
        return $obj;
    }
}
