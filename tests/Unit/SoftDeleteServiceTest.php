<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\SoftDeleteService;

class SoftDeleteServiceTest extends TestCase
{
    public function testApplyCriteriaWithWildcardShowsAll(): void
    {
        $service = new SoftDeleteService('deleted_at');

        $result = $service->applyCriteria(['deleted_at' => '*', 'status' => 'active']);

        $this->assertArrayNotHasKey('deleted_at', $result);
        $this->assertEquals('active', $result['status']);
    }
}
