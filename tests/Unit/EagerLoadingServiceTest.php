<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\EagerLoadingService;

class EagerLoadingServiceTest extends TestCase
{
    public function testGetRelations(): void
    {
        $service = new EagerLoadingService();
        $this->assertEquals([], $service->getRelations());

        $service->setRelations(['comments', 'user']);
        $this->assertEquals(['comments', 'user'], $service->getRelations());
    }

    public function testGroupByTopLevelWithInvalidRelations(): void
    {
        $service = new EagerLoadingService();
        $grouped = $service->groupByTopLevel(['', '.field', null]);
        $this->assertEquals([], $grouped);
    }
}
