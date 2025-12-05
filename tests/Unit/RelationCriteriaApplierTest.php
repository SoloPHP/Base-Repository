<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\RelationCriteriaApplier;

class RelationCriteriaApplierTest extends TestCase
{
    private Connection $connection;
    private RelationCriteriaApplier $applier;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->applier = new RelationCriteriaApplier($this->connection);
    }

    private function getCompiledRelations(): array
    {
        return [
            'comments' => [
                'type' => 'hasMany',
                'foreignKey' => 'post_id',
                'relatedTable' => 'comments',
                'relatedPrimaryKey' => 'id',
            ],
        ];
    }

    public function testApplyIgnoresUnknownRelation(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $initialSql = $qb->getSQL();

        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['unknown' => ['f' => 'v']]);

        $this->assertEquals($initialSql, $qb->getSQL());
    }

    public function testApplyIgnoresEmptyField(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');

        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), [
            'comments' => ['' => 'value', 'status' => 'ok'],
        ]);

        $this->assertStringContainsString('status', $qb->getSQL());
    }

    public function testApplyIgnoresUnsupportedRelationType(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $initialSql = $qb->getSQL();

        $compiled = ['rel' => ['type' => 'unsupported', 'foreignKey' => 'x', 'relatedTable' => 't', 'relatedPrimaryKey' => 'id']];
        $this->applier->apply($qb, 'p', 'id', $compiled, ['rel' => ['f' => 'v']]);

        $this->assertEquals($initialSql, $qb->getSQL());
    }

    public function testApplyWithNullValue(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['deleted_at' => null]]);
        $this->assertStringContainsString('IS NULL', $qb->getSQL());
    }

    public function testApplyWithIsNullOperator(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['deleted_at' => ['=', null]]]);
        $this->assertStringContainsString('IS NULL', $qb->getSQL());
    }

    public function testApplyWithIsNotNullOperator(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['deleted_at' => ['!=', null]]]);
        $this->assertStringContainsString('IS NOT NULL', $qb->getSQL());
    }

    public function testApplyWithNotEqualNullOperator(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['deleted_at' => ['<>', null]]]);
        $this->assertStringContainsString('IS NOT NULL', $qb->getSQL());
    }

    public function testApplyWithEmptyInList(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => []]]);
        $this->assertStringContainsString('1 = 0', $qb->getSQL());
    }

    public function testApplyWithEmptyInOperator(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['IN', []]]]);
        $this->assertStringContainsString('1 = 0', $qb->getSQL());
    }

    public function testApplyWithEmptyNotInOperator(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['NOT IN', []]]]);
        $this->assertStringContainsString('1 = 1', $qb->getSQL());
    }

    public function testApplyWithInOperatorSingleValue(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['IN', 'approved']]]);
        $this->assertStringContainsString('IN', $qb->getSQL());
    }

    public function testApplyWithNotInOperator(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['NOT IN', ['spam']]]]);
        $this->assertStringContainsString('NOT IN', $qb->getSQL());
    }

    public function testApplyThrowsExceptionForUnsafeOperator(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe operator');

        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['s' => ['DROP', 'v']]]);
    }

    public function testApplyWithInOperatorIntegerList(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['priority' => [1, 2, 3]]]);
        $this->assertStringContainsString('IN', $qb->getSQL());
    }
}
