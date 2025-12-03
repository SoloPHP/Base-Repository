<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\CriteriaBuilder;

class CriteriaBuilderTest extends TestCase
{
    private CriteriaBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CriteriaBuilder('t');
    }

    public function testApplyCriteriaWithEquality(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['status' => 'active']);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('= :status', $sql);
    }

    public function testApplyCriteriaWithNull(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['deleted_at' => null]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('IS NULL', $sql);
    }

    public function testApplyCriteriaWithInList(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['id' => [1, 2, 3]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('IN', $sql);
    }

    public function testApplyCriteriaWithOperator(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['age' => ['>=', 18]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('>=', $sql);
    }

    public function testApplyCriteriaWithNullOperator(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['deleted_at' => ['=', null]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('IS NULL', $sql);
    }

    public function testApplyCriteriaWithNotNullOperator(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['deleted_at' => ['!=', null]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    public function testApplyOrderBy(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyOrderBy($qb, ['name' => 'ASC']);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('name', $sql);

        // Test second order by
        $qb2 = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');
        $this->builder->applyOrderBy($qb2, ['created_at' => 'DESC']);
        $sql2 = $qb2->getSQL();
        $this->assertStringContainsString('created_at', $sql2);
    }

    public function testApplyCriteriaWithoutAlias(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users');

        $this->builder->applyCriteria($qb, ['status' => 'active'], false);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('status = :status', $sql);
        $this->assertStringNotContainsString('t.status', $sql);
    }

    public function testUnsafeIdentifierThrowsException(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        $this->builder->applyCriteria($qb, ['1invalid' => 'value']);
    }

    public function testUnsafeOperatorThrowsException(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe operator');

        $this->builder->applyCriteria($qb, ['age' => ['UNSAFE', 18]]);
    }

    public function testApplyCriteriaWithInOperator(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['status' => ['IN', ['active', 'pending']]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('IN', $sql);
    }

    public function testApplyCriteriaWithNotInOperator(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['status' => ['NOT IN', ['deleted', 'banned']]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('NOT IN', $sql);
    }

    public function testApplyCriteriaWithInOperatorSingleValue(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['status' => ['IN', 'active']]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('IN', $sql);
    }

    public function testApplyCriteriaWithInOperatorIntegerList(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['id' => ['IN', [1, 2, 3]]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('IN', $sql);
    }

    public function testApplyCriteriaWithNotEqualNullOperator(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        $this->builder->applyCriteria($qb, ['deleted_at' => ['<>', null]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    public function testApplyCriteriaWithStringInList(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()
            ->select('*')
            ->from('users', 't');

        // Use explicit IN operator for string lists
        $this->builder->applyCriteria($qb, ['name' => ['IN', ['Alice', 'Bob', 'Charlie']]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('IN', $sql);
    }
}
