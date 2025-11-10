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

    public function testSplitCriteriaWithRegularRelation(): void
    {
        $criteria = [
            'status' => 'active',
            'comments.status' => 'approved',
            'user.role' => 'admin',
        ];

        $relationConfig = [
            'comments' => [],
            'user' => [],
        ];

        [$baseCriteria, $relationCriteria] = $this->applier->splitCriteria($criteria, $relationConfig);

        $this->assertEquals(['status' => 'active'], $baseCriteria);
        $this->assertArrayHasKey('comments', $relationCriteria);
        $this->assertArrayHasKey('user', $relationCriteria);
        $this->assertEquals(['status' => 'approved'], $relationCriteria['comments']);
        $this->assertEquals(['role' => 'admin'], $relationCriteria['user']);
    }

    public function testSplitCriteriaWithNotExistsPrefix(): void
    {
        $criteria = [
            'status' => 'active',
            '!comments.status' => 'approved',
            'user.role' => 'admin',
            '!user.deleted_at' => null,
        ];

        $relationConfig = [
            'comments' => [],
            'user' => [],
        ];

        [$baseCriteria, $relationCriteria] = $this->applier->splitCriteria($criteria, $relationConfig);

        $this->assertEquals(['status' => 'active'], $baseCriteria);
        $this->assertArrayHasKey('!comments', $relationCriteria);
        $this->assertArrayHasKey('user', $relationCriteria);
        $this->assertArrayHasKey('!user', $relationCriteria);
        $this->assertEquals(['status' => 'approved'], $relationCriteria['!comments']);
        $this->assertEquals(['role' => 'admin'], $relationCriteria['user']);
        $this->assertEquals(['deleted_at' => null], $relationCriteria['!user']);
    }

    public function testSplitCriteriaIgnoresUnknownRelations(): void
    {
        $criteria = [
            'status' => 'active',
            'unknown.field' => 'value',
        ];

        $relationConfig = [
            'comments' => [],
        ];

        [$baseCriteria, $relationCriteria] = $this->applier->splitCriteria($criteria, $relationConfig);

        $this->assertEquals(['status' => 'active', 'unknown.field' => 'value'], $baseCriteria);
        $this->assertEmpty($relationCriteria);
    }

    public function testSplitCriteriaWithEmptyField(): void
    {
        $criteria = [
            'comments.' => 'value',
        ];

        $relationConfig = [
            'comments' => [],
        ];

        [$baseCriteria, $relationCriteria] = $this->applier->splitCriteria($criteria, $relationConfig);

        $this->assertEquals(['comments.' => 'value'], $baseCriteria);
        $this->assertEmpty($relationCriteria);
    }

    public function testApplyWithExistsForHasMany(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $compiledRelations = [
            'comments' => [
                'type' => 'hasMany',
                'foreignKey' => 'post_id',
                'relatedTable' => 'comments',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            'comments' => [
                'status' => 'approved',
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringNotContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('comments', $sql);
        $this->assertStringContainsString('rel_comments', $sql);
    }

    public function testApplyWithNotExistsForHasMany(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $compiledRelations = [
            'comments' => [
                'type' => 'hasMany',
                'foreignKey' => 'post_id',
                'relatedTable' => 'comments',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            '!comments' => [
                'status' => 'approved',
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('comments', $sql);
        $this->assertStringContainsString('rel_comments', $sql);
    }

    public function testApplyWithExistsForBelongsTo(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $compiledRelations = [
            'user' => [
                'type' => 'belongsTo',
                'foreignKey' => 'user_id',
                'relatedTable' => 'users',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            'user' => [
                'role' => 'admin',
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringNotContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringContainsString('rel_user', $sql);
    }

    public function testApplyWithNotExistsForBelongsTo(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $compiledRelations = [
            'user' => [
                'type' => 'belongsTo',
                'foreignKey' => 'user_id',
                'relatedTable' => 'users',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            '!user' => [
                'deleted_at' => null,
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringContainsString('rel_user', $sql);
    }

    public function testApplyWithNullValue(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $compiledRelations = [
            'comments' => [
                'type' => 'hasMany',
                'foreignKey' => 'post_id',
                'relatedTable' => 'comments',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            'comments' => [
                'deleted_at' => null,
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('IS NULL', $sql);
    }

    public function testApplyWithInList(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $compiledRelations = [
            'comments' => [
                'type' => 'hasMany',
                'foreignKey' => 'post_id',
                'relatedTable' => 'comments',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            'comments' => [
                'status' => [1, 2, 3],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('IN', $sql);
    }

    public function testApplyWithOperator(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $compiledRelations = [
            'comments' => [
                'type' => 'hasMany',
                'foreignKey' => 'post_id',
                'relatedTable' => 'comments',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            'comments' => [
                'created_at' => ['>=', '2024-01-01'],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('>=', $sql);
    }

    public function testApplyIgnoresUnknownRelation(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $initialSql = $qb->getSQL();

        $compiledRelations = [
            'comments' => [
                'type' => 'hasMany',
                'foreignKey' => 'post_id',
                'relatedTable' => 'comments',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            'unknown' => [
                'field' => 'value',
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        // SQL should not change (no EXISTS clause added)
        $finalSql = $qb->getSQL();
        $this->assertEquals($initialSql, $finalSql);
        $this->assertStringNotContainsString('EXISTS', $finalSql);
    }

    public function testApplyWithMultipleFields(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $compiledRelations = [
            'comments' => [
                'type' => 'hasMany',
                'foreignKey' => 'post_id',
                'relatedTable' => 'comments',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            'comments' => [
                'status' => 'approved',
                'type' => 'review',
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('type', $sql);
    }

    public function testApplyWithNotExistsAndMultipleFields(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $compiledRelations = [
            'comments' => [
                'type' => 'hasMany',
                'foreignKey' => 'post_id',
                'relatedTable' => 'comments',
                'relatedPrimaryKey' => 'id',
            ],
        ];

        $relationCriteria = [
            '!comments' => [
                'status' => 'approved',
                'deleted_at' => null,
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('IS NULL', $sql);
    }
}

