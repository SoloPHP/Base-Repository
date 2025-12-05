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

    public function testUnsafeIdentifierThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        $this->builder->applyCriteria($this->createQueryBuilder(), ['1invalid' => 'value']);
    }

    public function testUnsafeOperatorThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe operator');

        $this->builder->applyCriteria($this->createQueryBuilder(), ['age' => ['UNSAFE', 18]]);
    }

    public function testApplyCriteriaWithNullOperator(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['deleted_at' => ['=', null]]);
        $this->assertStringContainsString('IS NULL', $qb->getSQL());
    }

    public function testApplyCriteriaWithNotNullOperator(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['deleted_at' => ['!=', null]]);
        $this->assertStringContainsString('IS NOT NULL', $qb->getSQL());
    }

    public function testApplyCriteriaWithNotEqualNullOperator(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['deleted_at' => ['<>', null]]);
        $this->assertStringContainsString('IS NOT NULL', $qb->getSQL());
    }

    public function testApplyCriteriaWithInOperatorSingleValue(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['status' => ['IN', 'active']]);
        $this->assertStringContainsString('IN', $qb->getSQL());
    }

    public function testApplyCriteriaWithNotInOperator(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['status' => ['NOT IN', ['deleted', 'banned']]]);
        $this->assertStringContainsString('NOT IN', $qb->getSQL());
    }
}
