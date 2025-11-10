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
}

