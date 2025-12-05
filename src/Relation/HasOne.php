<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Relation;

final readonly class HasOne implements RelationType
{
    /**
     * @param string $repository Repository property name
     * @param string $foreignKey Foreign key column in related table
     * @param string $setter Setter method name in model
     * @param array<string, string> $orderBy Optional ordering
     */
    public function __construct(
        public string $repository,
        public string $foreignKey,
        public string $setter,
        public array $orderBy = [],
    ) {
    }

    public function getType(): string
    {
        return 'hasOne';
    }

    public function getRepository(): string
    {
        return $this->repository;
    }

    public function getSetter(): string
    {
        return $this->setter;
    }

    public function getOrderBy(): array
    {
        return $this->orderBy;
    }
}
