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

    public function testApplyCriteriaWithBetweenOperator(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['price' => ['BETWEEN' => [100, 500]]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('t.price BETWEEN :price_min AND :price_max', $sql);
        $this->assertEquals(100, $qb->getParameter('price_min'));
        $this->assertEquals(500, $qb->getParameter('price_max'));
    }

    public function testApplyCriteriaWithBetweenOperatorLowercase(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['age' => ['between' => [18, 65]]]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('BETWEEN', $sql);
        $this->assertEquals(18, $qb->getParameter('age_min'));
        $this->assertEquals(65, $qb->getParameter('age_max'));
    }

    public function testApplyCriteriaWithBetweenOperatorDates(): void
    {
        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, [
            'created_at' => ['BETWEEN' => ['2024-01-01', '2024-12-31']]
        ]);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('BETWEEN', $sql);
        $this->assertEquals('2024-01-01', $qb->getParameter('created_at_min'));
        $this->assertEquals('2024-12-31', $qb->getParameter('created_at_max'));
    }

    public function testApplyCriteriaWithBetweenOperatorInvalidArrayThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BETWEEN requires array of exactly 2 values');

        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['price' => ['BETWEEN' => [100]]]);
    }

    public function testApplyCriteriaWithBetweenOperatorTooManyValuesThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BETWEEN requires array of exactly 2 values');

        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['price' => ['BETWEEN' => [100, 200, 300]]]);
    }

    public function testApplyCriteriaWithBetweenOperatorNonArrayThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BETWEEN requires array of exactly 2 values');

        $qb = $this->createQueryBuilder();
        $this->builder->applyCriteria($qb, ['price' => ['BETWEEN' => 100]]);
    }
}
