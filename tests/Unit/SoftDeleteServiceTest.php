<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\SoftDeleteService;

class SoftDeleteServiceTest extends TestCase
{
    public function testApplyCriteriaAddsDeletedWithoutWhenNotSet(): void
    {
        $service = new SoftDeleteService('deleted_at');

        $criteria = ['status' => 'active'];
        $result = $service->applyCriteria($criteria);

        $this->assertArrayHasKey('deleted', $result);
        $this->assertEquals('without', $result['deleted']);
    }

    public function testApplyCriteriaPreservesDeletedWhenSet(): void
    {
        $service = new SoftDeleteService('deleted_at');

        $criteria = ['deleted' => 'only', 'status' => 'active'];
        $result = $service->applyCriteria($criteria);

        $this->assertEquals('only', $result['deleted']);
    }

    public function testGetSoftDeleteData(): void
    {
        $service = new SoftDeleteService('deleted_at');

        $data = $service->getSoftDeleteData();

        $this->assertArrayHasKey('deleted_at', $data);
        $this->assertIsString($data['deleted_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['deleted_at']);
    }

    public function testGetRestoreData(): void
    {
        $service = new SoftDeleteService('deleted_at');

        $data = $service->getRestoreData();

        $this->assertArrayHasKey('deleted_at', $data);
        $this->assertNull($data['deleted_at']);
    }

    public function testGetDeletedAtColumn(): void
    {
        $service = new SoftDeleteService('deleted_at');

        $this->assertEquals('deleted_at', $service->getDeletedAtColumn());
    }

    public function testCustomDeletedAtColumn(): void
    {
        $service = new SoftDeleteService('removed_at');

        $this->assertEquals('removed_at', $service->getDeletedAtColumn());

        $data = $service->getSoftDeleteData();
        $this->assertArrayHasKey('removed_at', $data);
    }
}
