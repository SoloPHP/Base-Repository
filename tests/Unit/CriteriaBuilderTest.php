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

    public function testApplyCriteriaWithBetweenOperatorInvalidArrayThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['price' => ['BETWEEN' => [100]]]);
    }
}
