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

    public function testApplyCriteriaWithoutAlias(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()->select('*')->from('users');

        $this->builder->applyCriteria($qb, ['status' => 'active'], false);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('status = :status', $sql);
        $this->assertStringNotContainsString('t.status', $sql);
    }

    public function testApplyCriteriaWithNullOperator(): void
    {
        // ['=' => null] - IS NULL
        $qb1 = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb1, ['deleted_at' => ['=' => null]]);
        $this->assertStringContainsString('IS NULL', $qb1->getSQL());

        // ['<>' => null] - IS NOT NULL
        $qb2 = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb2, ['deleted_at' => ['<>' => null]]);
        $this->assertStringContainsString('IS NOT NULL', $qb2->getSQL());
    }

    public function testApplyCriteriaWithInOperator(): void
    {
        // Single value
        $qb1 = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb1, ['status' => ['IN' => 'active']]);
        $this->assertStringContainsString('IN', $qb1->getSQL());

        // NOT IN
        $qb2 = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb2, ['status' => ['NOT IN' => ['deleted', 'banned']]]);
        $this->assertStringContainsString('NOT IN', $qb2->getSQL());
    }

    public function testApplyCriteriaWithLikeOperator(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['email' => ['LIKE' => '%@gmail%']]);
        $this->assertStringContainsString('LIKE :email', $qb->getSQL());
    }
}
