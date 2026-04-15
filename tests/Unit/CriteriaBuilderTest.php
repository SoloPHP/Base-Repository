<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\CriteriaBuilder;

class CriteriaBuilderTest extends TestCase
{
    private CriteriaBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CriteriaBuilder('t');
    }

    private function createQueryBuilder()
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        return $connection->createQueryBuilder()->select('*')->from('users', 't');
    }

    public function testApplyCriteriaWithNullOperator(): void
    {
        $qb1 = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb1, ['deleted_at' => ['=' => null]]);
        $this->assertStringContainsString('IS NULL', $qb1->getSQL());

        $qb2 = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb2, ['deleted_at' => ['<>' => null]]);
        $this->assertStringContainsString('IS NOT NULL', $qb2->getSQL());
    }

    public function testApplyCriteriaWithInOperator(): void
    {
        $qb1 = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb1, ['status' => ['IN' => 'active']]);
        $this->assertStringContainsString('IN', $qb1->getSQL());

        $qb2 = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb2, ['status' => ['NOT IN' => ['deleted', 'banned']]]);
        $this->assertStringContainsString('NOT IN', $qb2->getSQL());
    }

    public function testApplyCriteriaWithSequentialInList(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['status_id' => [1, 2, 3]]);
        $this->assertStringContainsString('IN', $qb->getSQL());
    }

    public function testApplyCriteriaWithNonSequentialInList(): void
    {
        $qb = $this->createQueryBuilder();
        // Simulates result of array_unique/array_filter: gaps in integer keys
        $this->builder->applyCriteria($qb, ['status_id' => [0 => 5, 2 => 8]]);
        $this->assertStringContainsString('IN', $qb->getSQL());
    }

    public function testApplyCriteriaWithOperatorSyntax(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['price' => ['>' => 5]]);
        $this->assertStringContainsString('> :price', $qb->getSQL());
    }

    public function testApplyCriteriaWithNullNotEqual(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['deleted_at' => ['!=' => null]]);
        $this->assertStringContainsString('IS NOT NULL', $qb->getSQL());
    }

    public function testApplyCriteriaWithBetweenOperatorInvalidArrayThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['price' => ['BETWEEN' => [100]]]);
    }
}
