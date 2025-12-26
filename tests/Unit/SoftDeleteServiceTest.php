<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\SoftDeleteService;

class SoftDeleteServiceTest extends TestCase
{
    public function testApplyCriteriaAddsDeletedAtNullWhenNotSet(): void
    {
        $service = new SoftDeleteService('deleted_at');

        $criteria = ['status' => 'active'];
        $result = $service->applyCriteria($criteria);

        $this->assertArrayHasKey('deleted_at', $result);
        $this->assertNull($result['deleted_at']);
    }

    public function testApplyCriteriaPreservesDeletedAtWhenSet(): void
    {
        $service = new SoftDeleteService('deleted_at');

        $criteria = ['deleted_at' => ['!=' => null], 'status' => 'active'];
        $result = $service->applyCriteria($criteria);

        $this->assertEquals(['!=' => null], $result['deleted_at']);
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

    public function testCustomDeletedAtColumn(): void
    {
        $service = new SoftDeleteService('removed_at');

        $data = $service->getSoftDeleteData();
        $this->assertArrayHasKey('removed_at', $data);

        $restoreData = $service->getRestoreData();
        $this->assertArrayHasKey('removed_at', $restoreData);

        $criteria = $service->applyCriteria([]);
        $this->assertArrayHasKey('removed_at', $criteria);
    }

    public function testApplyCriteriaWithWildcardShowsAll(): void
    {
        $service = new SoftDeleteService('deleted_at');

        $criteria = ['deleted_at' => '*', 'status' => 'active'];
        $result = $service->applyCriteria($criteria);

        $this->assertArrayNotHasKey('deleted_at', $result);
        $this->assertEquals('active', $result['status']);
    }
}
