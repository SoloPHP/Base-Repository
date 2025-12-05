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

    public function testApplyWithNullHandling(): void
    {
        // Direct null value
        $qb1 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb1, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['deleted_at' => null]]);
        $this->assertStringContainsString('IS NULL', $qb1->getSQL());

        // Explicit = null operator
        $qb2 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb2, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['deleted_at' => ['=', null]]]);
        $this->assertStringContainsString('IS NULL', $qb2->getSQL());

        // != null operator
        $qb3 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb3, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['deleted_at' => ['!=', null]]]);
        $this->assertStringContainsString('IS NOT NULL', $qb3->getSQL());

        // <> null operator
        $qb4 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb4, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['deleted_at' => ['<>', null]]]);
        $this->assertStringContainsString('IS NOT NULL', $qb4->getSQL());
    }

    public function testApplyWithEmptyArrayHandling(): void
    {
        // Empty array defaults to IN with empty
        $qb1 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb1, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => []]]);
        $this->assertStringContainsString('1 = 0', $qb1->getSQL());

        // Explicit empty IN
        $qb2 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb2, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['IN', []]]]);
        $this->assertStringContainsString('1 = 0', $qb2->getSQL());

        // Empty NOT IN
        $qb3 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb3, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['NOT IN', []]]]);
        $this->assertStringContainsString('1 = 1', $qb3->getSQL());
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
