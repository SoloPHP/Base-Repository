<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\BelongsToMany;
use Solo\BaseRepository\Relation\HasMany;
use Solo\BaseRepository\Relation\HasOne;

class RelationDtoTest extends TestCase
{
    public function testHasOneWithOrderBy(): void
    {
        $relation = new HasOne(
            repository: 'profileRepository',
            foreignKey: 'user_id',
            setter: 'setProfile',
            orderBy: ['created_at' => 'DESC'],
        );

        $this->assertEquals('hasOne', $relation->getType());
        $this->assertEquals('profileRepository', $relation->getRepository());
        $this->assertEquals('setProfile', $relation->getSetter());
        $this->assertEquals(['created_at' => 'DESC'], $relation->getOrderBy());
    }
}
