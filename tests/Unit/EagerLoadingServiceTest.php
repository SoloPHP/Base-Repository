<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\EagerLoadingService;

class EagerLoadingServiceTest extends TestCase
{
    private EagerLoadingService $service;

    protected function setUp(): void
    {
        $this->service = new EagerLoadingService();
    }

    public function testSetRelations(): void
    {
        $this->service->setRelations(['comments', 'user']);

        $this->assertTrue($this->service->hasRelations());
        $this->assertEquals(['comments', 'user'], $this->service->getRelations());
    }

    public function testHasRelationsReturnsFalseWhenEmpty(): void
    {
        $this->assertFalse($this->service->hasRelations());
    }

    public function testHasRelationsReturnsTrueWhenSet(): void
    {
        $this->service->setRelations(['comments']);
        $this->assertTrue($this->service->hasRelations());
    }

    public function testReset(): void
    {
        $this->service->setRelations(['comments', 'user']);
        $this->assertTrue($this->service->hasRelations());

        $this->service->reset();

        $this->assertFalse($this->service->hasRelations());
        $this->assertEquals([], $this->service->getRelations());
    }

    public function testLoadRelationsReturnsItemsWhenEmpty(): void
    {
        $items = [new \stdClass()];
        $result = $this->service->loadRelations($items, fn($items) => $items);

        $this->assertEquals($items, $result);
    }

    public function testLoadRelationsCallsLoader(): void
    {
        $this->service->setRelations(['comments']);

        $items = [new \stdClass()];
        $called = false;
        $loader = function ($items, $relations) use (&$called) {
            $called = true;
            return $items;
        };

        $this->service->loadRelations($items, $loader);

        $this->assertTrue($called);
    }

    public function testGroupByTopLevel(): void
    {
        $relations = ['comments', 'comments.user', 'author.profile.avatar'];
        $grouped = $this->service->groupByTopLevel($relations);

        $this->assertArrayHasKey('comments', $grouped);
        $this->assertArrayHasKey('author', $grouped);
        $this->assertContains('user', $grouped['comments']);
        $this->assertContains('profile.avatar', $grouped['author']);
    }

    public function testGroupByTopLevelWithEmptyRelations(): void
    {
        $grouped = $this->service->groupByTopLevel([]);
        $this->assertEquals([], $grouped);
    }

    public function testGroupByTopLevelWithInvalidRelations(): void
    {
        $relations = ['', 'comments', null];
        $grouped = $this->service->groupByTopLevel($relations);

        $this->assertArrayHasKey('comments', $grouped);
    }

    public function testLoadRelationWithHasOne(): void
    {
        // Mock items with IDs and setter method
        $item1 = new class {
            public int $id = 1;
            public ?object $profile = null;

            public function setProfile(?object $profile): void
            {
                $this->profile = $profile;
            }
        };

        $item2 = new class {
            public int $id = 2;
            public ?object $profile = null;

            public function setProfile(?object $profile): void
            {
                $this->profile = $profile;
            }
        };

        $items = [$item1, $item2];

        // Mock related items
        $related1 = new \stdClass();
        $related1->id = 10;
        $related1->user_id = 1;

        $related2 = new \stdClass();
        $related2->id = 20;
        $related2->user_id = 2;

        // Mock repository
        $mockRepo = new class {
            public array $items = [];

            public function findBy(array $criteria, ?array $sort = null): array
            {
                return $this->items;
            }
        };
        $mockRepo->items = [$related1, $related2];

        // Mock parent repository
        $parentRepo = new \stdClass();
        $parentRepo->profileRepository = $mockRepo;

        // Relation config for hasOne
        $relationConfig = [
            'profile' => ['hasOne', 'profileRepository', 'user_id', 'setProfile', []],
        ];

        // Load relation
        $this->service->loadRelation($items, 'profile', $relationConfig, $parentRepo);

        // Verify that setter was called with correct related item
        $this->assertNotNull($item1->profile);
        $this->assertNotNull($item2->profile);
        $this->assertEquals(10, $item1->profile->id);
        $this->assertEquals(20, $item2->profile->id);
    }

    public function testLoadRelationWithHasOneWhenNoItems(): void
    {
        $items = [];

        $mockRepo = new class {
            public function findBy(array $criteria, ?array $sort = null): array
            {
                return [];
            }
        };

        $parentRepo = new \stdClass();
        $parentRepo->profileRepository = $mockRepo;

        $relationConfig = [
            'profile' => ['hasOne', 'profileRepository', 'user_id', 'setProfile', []],
        ];

        // Should not throw any errors
        $this->service->loadRelation($items, 'profile', $relationConfig, $parentRepo);
        $this->assertEmpty($items);
    }

    public function testLoadRelationWithHasOneWhenNoRelatedItems(): void
    {
        $item1 = new class {
            public int $id = 1;
            public ?object $profile = null;

            public function setProfile(?object $profile): void
            {
                $this->profile = $profile;
            }
        };
        $items = [$item1];

        $mockRepo = new class {
            public function findBy(array $criteria, ?array $sort = null): array
            {
                return [];
            }
        };

        $parentRepo = new \stdClass();
        $parentRepo->profileRepository = $mockRepo;

        $relationConfig = [
            'profile' => ['hasOne', 'profileRepository', 'user_id', 'setProfile', []],
        ];

        $this->service->loadRelation($items, 'profile', $relationConfig, $parentRepo);

        // Should set null when no related items found
        $this->assertNull($item1->profile);
    }

    public function testLoadRelationWithHasOneSelectsFirstMatch(): void
    {
        $item1 = new class {
            public int $id = 1;
            public ?object $profile = null;

            public function setProfile(?object $profile): void
            {
                $this->profile = $profile;
            }
        };
        $items = [$item1];

        // Multiple related items with same foreign key (hasOne should pick first)
        $related1 = new \stdClass();
        $related1->id = 10;
        $related1->user_id = 1;

        $related2 = new \stdClass();
        $related2->id = 20;
        $related2->user_id = 1; // Same foreign key

        $mockRepo = new class {
            public array $items = [];

            public function findBy(array $criteria, ?array $sort = null): array
            {
                return $this->items;
            }
        };
        $mockRepo->items = [$related1, $related2];

        $parentRepo = new \stdClass();
        $parentRepo->profileRepository = $mockRepo;

        $relationConfig = [
            'profile' => ['hasOne', 'profileRepository', 'user_id', 'setProfile', []],
        ];

        $this->service->loadRelation($items, 'profile', $relationConfig, $parentRepo);

        // Should only have the first match
        $this->assertNotNull($item1->profile);
        $this->assertEquals(10, $item1->profile->id); // First match
    }

    public function testLoadRelationWithHasMany(): void
    {
        $item1 = new class {
            public int $id = 1;
            public array $comments = [];

            public function setComments(array $comments): void
            {
                $this->comments = $comments;
            }
        };

        $item2 = new class {
            public int $id = 2;
            public array $comments = [];

            public function setComments(array $comments): void
            {
                $this->comments = $comments;
            }
        };

        $items = [$item1, $item2];

        $comment1 = new \stdClass();
        $comment1->id = 10;
        $comment1->post_id = 1;

        $comment2 = new \stdClass();
        $comment2->id = 20;
        $comment2->post_id = 1;

        $comment3 = new \stdClass();
        $comment3->id = 30;
        $comment3->post_id = 2;

        $mockRepo = new class {
            public array $items = [];

            public function findBy(array $criteria, ?array $sort = null): array
            {
                return $this->items;
            }
        };
        $mockRepo->items = [$comment1, $comment2, $comment3];

        $parentRepo = new \stdClass();
        $parentRepo->commentRepository = $mockRepo;

        $relationConfig = [
            'comments' => ['hasMany', 'commentRepository', 'post_id', 'setComments', []],
        ];

        $this->service->loadRelation($items, 'comments', $relationConfig, $parentRepo);

        $this->assertCount(2, $item1->comments);
        $this->assertCount(1, $item2->comments);
        $this->assertEquals(10, $item1->comments[0]->id);
        $this->assertEquals(20, $item1->comments[1]->id);
        $this->assertEquals(30, $item2->comments[0]->id);
    }

    public function testLoadRelationWithHasManyWhenNoItems(): void
    {
        $items = [];

        $mockRepo = new class {
            public function findBy(array $criteria, ?array $sort = null): array
            {
                return [];
            }
        };

        $parentRepo = new \stdClass();
        $parentRepo->commentRepository = $mockRepo;

        $relationConfig = [
            'comments' => ['hasMany', 'commentRepository', 'post_id', 'setComments', []],
        ];

        $this->service->loadRelation($items, 'comments', $relationConfig, $parentRepo);
        $this->assertEmpty($items);
    }

    public function testLoadRelationWithHasManyWhenNoRelatedItems(): void
    {
        $item1 = new class {
            public int $id = 1;
            public array $comments = [];

            public function setComments(array $comments): void
            {
                $this->comments = $comments;
            }
        };
        $items = [$item1];

        $mockRepo = new class {
            public function findBy(array $criteria, ?array $sort = null): array
            {
                return [];
            }
        };

        $parentRepo = new \stdClass();
        $parentRepo->commentRepository = $mockRepo;

        $relationConfig = [
            'comments' => ['hasMany', 'commentRepository', 'post_id', 'setComments', []],
        ];

        $this->service->loadRelation($items, 'comments', $relationConfig, $parentRepo);

        $this->assertEmpty($item1->comments);
    }

    public function testLoadRelationWithBelongsTo(): void
    {
        $item1 = new class {
            public int $id = 1;
            public int $user_id = 100;
            public ?object $user = null;

            public function setUser(?object $user): void
            {
                $this->user = $user;
            }
        };

        $item2 = new class {
            public int $id = 2;
            public int $user_id = 200;
            public ?object $user = null;

            public function setUser(?object $user): void
            {
                $this->user = $user;
            }
        };

        $items = [$item1, $item2];

        $user1 = new \stdClass();
        $user1->id = 100;

        $user2 = new \stdClass();
        $user2->id = 200;

        $mockRepo = new class {
            public array $items = [];

            public function findBy(array $criteria, ?array $sort = null): array
            {
                return $this->items;
            }
        };
        $mockRepo->items = [$user1, $user2];

        $parentRepo = new \stdClass();
        $parentRepo->userRepository = $mockRepo;

        $relationConfig = [
            'user' => ['belongsTo', 'userRepository', 'user_id', 'setUser'],
        ];

        $this->service->loadRelation($items, 'user', $relationConfig, $parentRepo);

        $this->assertNotNull($item1->user);
        $this->assertNotNull($item2->user);
        $this->assertEquals(100, $item1->user->id);
        $this->assertEquals(200, $item2->user->id);
    }

    public function testLoadRelationWithBelongsToWhenNoRelatedItems(): void
    {
        $item1 = new class {
            public int $id = 1;
            public int $user_id = 100;
            public ?object $user = null;

            public function setUser(?object $user): void
            {
                $this->user = $user;
            }
        };
        $items = [$item1];

        $mockRepo = new class {
            public function findBy(array $criteria, ?array $sort = null): array
            {
                return [];
            }
        };

        $parentRepo = new \stdClass();
        $parentRepo->userRepository = $mockRepo;

        $relationConfig = [
            'user' => ['belongsTo', 'userRepository', 'user_id', 'setUser'],
        ];

        $this->service->loadRelation($items, 'user', $relationConfig, $parentRepo);

        $this->assertNull($item1->user);
    }

    public function testLoadRelationWithUnknownRelation(): void
    {
        $item1 = new class {
            public int $id = 1;
        };
        $items = [$item1];

        $parentRepo = new \stdClass();

        $relationConfig = [];

        // Should not throw any errors
        $this->service->loadRelation($items, 'unknown', $relationConfig, $parentRepo);
        $this->assertCount(1, $items);
    }

    public function testLoadRelationWithNestedRelations(): void
    {
        $item1 = new class {
            public int $id = 1;
            public array $comments = [];

            public function setComments(array $comments): void
            {
                $this->comments = $comments;
            }
        };
        $items = [$item1];

        $comment1 = new \stdClass();
        $comment1->id = 10;
        $comment1->post_id = 1;

        $mockRepo = new class {
            public array $items = [];
            public bool $withCalled = false;
            public array $withRelations = [];

            public function findBy(array $criteria, ?array $sort = null): array
            {
                return $this->items;
            }

            public function with(array $relations): self
            {
                $this->withCalled = true;
                $this->withRelations = $relations;
                return $this;
            }
        };
        $mockRepo->items = [$comment1];

        $parentRepo = new \stdClass();
        $parentRepo->commentRepository = $mockRepo;

        $relationConfig = [
            'comments' => ['hasMany', 'commentRepository', 'post_id', 'setComments', []],
        ];

        $this->service->loadRelation($items, 'comments', $relationConfig, $parentRepo, ['user']);

        $this->assertTrue($mockRepo->withCalled);
        $this->assertEquals(['user'], $mockRepo->withRelations);
    }

    public function testLoadRelationsReturnsEmptyItemsWhenEmpty(): void
    {
        $this->service->setRelations(['comments']);
        $result = $this->service->loadRelations([], fn($items) => $items);
        $this->assertEquals([], $result);
    }

    public function testGroupByTopLevelWithDotOnly(): void
    {
        $relations = ['.field'];
        $grouped = $this->service->groupByTopLevel($relations);
        $this->assertEquals([], $grouped);
    }

    public function testLoadRelationWithBelongsToWhenNoIds(): void
    {
        $item1 = new class {
            public int $id = 1;
            public ?int $user_id = null;
            public ?object $user = null;

            public function setUser(?object $user): void
            {
                $this->user = $user;
            }
        };
        $items = [$item1];

        $mockRepo = new class {
            public function findBy(array $criteria, ?array $sort = null): array
            {
                return [];
            }
        };

        $parentRepo = new \stdClass();
        $parentRepo->userRepository = $mockRepo;

        $relationConfig = [
            'user' => ['belongsTo', 'userRepository', 'user_id', 'setUser'],
        ];

        $this->service->loadRelation($items, 'user', $relationConfig, $parentRepo);

        $this->assertNull($item1->user);
    }

    public function testGroupByTopLevelWithSingleRelation(): void
    {
        $relations = ['comments'];
        $grouped = $this->service->groupByTopLevel($relations);

        $this->assertArrayHasKey('comments', $grouped);
        $this->assertEquals([], $grouped['comments']);
    }

    public function testLoadRelationWithHasOneWhenEmptyIds(): void
    {
        $item1 = new class {
            public int $id = 0;
            public ?object $profile = null;

            public function setProfile(?object $profile): void
            {
                $this->profile = $profile;
            }
        };
        $items = [$item1];

        $findByCalled = false;
        $mockRepo = new class {
            public bool $findByCalled = false;

            public function findBy(array $criteria, ?array $sort = null): array
            {
                $this->findByCalled = true;
                return [];
            }
        };

        $parentRepo = new \stdClass();
        $parentRepo->profileRepository = $mockRepo;

        $relationConfig = [
            'profile' => ['hasOne', 'profileRepository', 'user_id', 'setProfile', []],
        ];

        $this->service->loadRelation($items, 'profile', $relationConfig, $parentRepo);

        // With id=0, IDs array will not be empty, so findBy should be called
        $this->assertTrue($mockRepo->findByCalled);
    }

    public function testLoadRelationWithHasManyEmptyItems(): void
    {
        $item1 = new class {
            public int $id = 0;
            public array $comments = [];

            public function setComments(array $comments): void
            {
                $this->comments = $comments;
            }
        };
        $items = [$item1];

        $mockRepo = new class {
            public bool $findByCalled = false;

            public function findBy(array $criteria, ?array $sort = null): array
            {
                $this->findByCalled = true;
                return [];
            }
        };

        $parentRepo = new \stdClass();
        $parentRepo->commentRepository = $mockRepo;

        $relationConfig = [
            'comments' => ['hasMany', 'commentRepository', 'post_id', 'setComments', []],
        ];

        $this->service->loadRelation($items, 'comments', $relationConfig, $parentRepo);

        // With id=0, IDs array will not be empty, so findBy should be called
        $this->assertTrue($mockRepo->findByCalled);
    }
}
