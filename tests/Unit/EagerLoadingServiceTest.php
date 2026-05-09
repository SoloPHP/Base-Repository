<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\EagerLoadingService;

class EagerLoadingServiceTest extends TestCase
{
    public function testHasRelations(): void
    {
        $service = new EagerLoadingService();
        $this->assertFalse($service->hasRelations());

        $service->setRelations(['comments', 'user']);
        $this->assertTrue($service->hasRelations());

        $service->reset();
        $this->assertFalse($service->hasRelations());
    }

    public function testGroupByTopLevelWithInvalidRelations(): void
    {
        $service = new EagerLoadingService();
        $grouped = $service->groupByTopLevel(['', '.field', null]);
        $this->assertEquals([], $grouped);
    }
}
