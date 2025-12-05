<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Relation;

interface RelationType
{
    public function getType(): string;

    public function getRepository(): string;

    public function getSetter(): string;

    /**
     * @return array<string, string>
     */
    public function getOrderBy(): array;
}
