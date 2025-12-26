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

    public function testApplyIgnoresUnknownRelationAndUnsupportedType(): void
    {
        // Unknown relation
        $qb1 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $initialSql = $qb1->getSQL();
        $this->applier->apply($qb1, 'p', 'id', $this->getCompiledRelations(), ['unknown' => ['f' => 'v']]);
        $this->assertEquals($initialSql, $qb1->getSQL());

        // Unsupported relation type
        $qb2 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $compiled = ['rel' => ['type' => 'unsupported', 'foreignKey' => 'x', 'relatedTable' => 't', 'relatedPrimaryKey' => 'id']];
        $this->applier->apply($qb2, 'p', 'id', $compiled, ['rel' => ['f' => 'v']]);
        $this->assertEquals($initialSql, $qb2->getSQL());

        // Empty field name
        $qb3 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb3, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['' => 'value', 'status' => 'ok']]);
        $this->assertStringContainsString('status', $qb3->getSQL());
    }

    public function testApplyWithNullHandling(): void
    {
        // Direct null, ['=' => null], ['!=' => null], ['<>' => null]
        $cases = [
            [['deleted_at' => null], 'IS NULL'],
            [['deleted_at' => ['=' => null]], 'IS NULL'],
            [['deleted_at' => ['!=' => null]], 'IS NOT NULL'],
            [['deleted_at' => ['<>' => null]], 'IS NOT NULL'],
        ];

        foreach ($cases as [$criteria, $expected]) {
            $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
            $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => $criteria]);
            $this->assertStringContainsString($expected, $qb->getSQL());
        }
    }

    public function testApplyWithEmptyArrayAndInOperators(): void
    {
        // Empty array = IN with empty (1 = 0)
        $qb1 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb1, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => []]]);
        $this->assertStringContainsString('1 = 0', $qb1->getSQL());

        // Empty IN = 1 = 0
        $qb2 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb2, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['IN' => []]]]);
        $this->assertStringContainsString('1 = 0', $qb2->getSQL());

        // Empty NOT IN = 1 = 1
        $qb3 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb3, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['NOT IN' => []]]]);
        $this->assertStringContainsString('1 = 1', $qb3->getSQL());

        // IN single value
        $qb4 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb4, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['IN' => 'approved']]]);
        $this->assertStringContainsString('IN', $qb4->getSQL());

        // NOT IN
        $qb5 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb5, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['status' => ['NOT IN' => ['spam']]]]);
        $this->assertStringContainsString('NOT IN', $qb5->getSQL());

        // Integer list (tests determineArrayParamType)
        $qb6 = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');
        $this->applier->apply($qb6, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['priority' => [1, 2, 3]]]);
        $this->assertStringContainsString('IN', $qb6->getSQL());
    }

    public function testApplyThrowsExceptionForUnsafeOperator(): void
    {
        $qb = $this->connection->createQueryBuilder()->select('*')->from('posts', 'p');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe operator');

        $this->applier->apply($qb, 'p', 'id', $this->getCompiledRelations(), ['comments' => ['s' => ['DROP' => 'v']]]);
    }
}
