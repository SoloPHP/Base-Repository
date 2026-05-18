<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Relation\HasOne;
use Solo\BaseRepository\Relation\RelationKind;

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

        $this->assertSame(RelationKind::HasOne, $relation->kind());
        $this->assertSame('profileRepository', $relation->repository);
        $this->assertSame('user_id', $relation->foreignKey);
        $this->assertSame('setProfile', $relation->setter);
        $this->assertSame(['created_at' => 'DESC'], $relation->orderBy);
    }
}
