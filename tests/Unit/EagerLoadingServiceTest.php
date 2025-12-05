<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\EagerLoadingService;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\HasMany;

class EagerLoadingServiceTest extends TestCase
{
    private EagerLoadingService $service;

    protected function setUp(): void
    {
        $this->service = new EagerLoadingService();
    }

    public function testGetRelations(): void
    {
        $this->service->setRelations(['comments', 'user']);
        $this->assertEquals(['comments', 'user'], $this->service->getRelations());
    }

    public function testLoadRelationWithUnknownRelation(): void
    {
        $item = new \stdClass();
        $item->id = 1;

        $this->service->loadRelation([$item], 'unknown', [], new \stdClass());
        $this->assertEquals(1, $item->id);
    }

    public function testLoadRelationWithNestedRelations(): void
    {
        $item = new class {
            public int $id = 1;
            public array $comments = [];
            public function setComments(array $c): void { $this->comments = $c; }
        };

        $comment = new \stdClass();
        $comment->id = 10;
        $comment->post_id = 1;

        $mockRepo = new class {
            public array $items = [];
            public bool $withCalled = false;
            public function findBy(array $c, ?array $s = null): array { return $this->items; }
            public function with(array $r): self { $this->withCalled = true; return $this; }
        };
        $mockRepo->items = [$comment];

        $parentRepo = new \stdClass();
        $parentRepo->commentRepository = $mockRepo;

        $relationConfig = [
            'comments' => new HasMany(
                repository: 'commentRepository',
                foreignKey: 'post_id',
                setter: 'setComments',
            ),
        ];

        $this->service->loadRelation([$item], 'comments', $relationConfig, $parentRepo, ['user']);
        $this->assertTrue($mockRepo->withCalled);
    }

    public function testGroupByTopLevelWithNestedRelations(): void
    {
        $relations = ['comments', 'comments.user', 'author.profile'];
        $grouped = $this->service->groupByTopLevel($relations);

        $this->assertArrayHasKey('comments', $grouped);
        $this->assertContains('user', $grouped['comments']);
        $this->assertContains('profile', $grouped['author']);
    }

    public function testGroupByTopLevelWithInvalidRelations(): void
    {
        $grouped = $this->service->groupByTopLevel(['', '.field', null]);
        $this->assertEquals([], $grouped);
    }

    public function testJoinBelongsToWhenNoRelatedItems(): void
    {
        $item = new class {
            public int $id = 1;
            public int $user_id = 100;
            public ?object $user = null;
            public function setUser(?object $u): void { $this->user = $u; }
        };

        $mockRepo = new class {
            public function findBy(array $c, ?array $s = null): array { return []; }
        };

        $parentRepo = new \stdClass();
        $parentRepo->userRepository = $mockRepo;

        $relationConfig = [
            'user' => new BelongsTo(
                repository: 'userRepository',
                foreignKey: 'user_id',
                setter: 'setUser',
            ),
        ];

        $this->service->loadRelation([$item], 'user', $relationConfig, $parentRepo);
        $this->assertNull($item->user);
    }
}
