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

    public function testApplyWithEmptyInList(): void
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
                'status' => [],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('1 = 0', $sql);
    }

    public function testApplyWithIsNullOperator(): void
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
                'deleted_at' => ['=', null],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('IS NULL', $sql);
    }

    public function testApplyWithIsNotNullOperator(): void
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
                'deleted_at' => ['!=', null],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    public function testApplyWithNotEqualNullOperator(): void
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
                'deleted_at' => ['<>', null],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    public function testApplyWithStringInList(): void
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

        // Use explicit IN operator for string lists to avoid confusion with [operator, value] syntax
        $relationCriteria = [
            'comments' => [
                'status' => ['IN', ['approved', 'pending']],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('IN', $sql);
    }

    public function testApplyIgnoresEmptyField(): void
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
                '' => 'value',
                'status' => 'approved',
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('status', $sql);
    }

    public function testApplyIgnoresUnsupportedRelationType(): void
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('posts', 'p');

        $initialSql = $qb->getSQL();

        $compiledRelations = [
            'comments' => [
                'type' => 'manyToMany', // Unsupported type
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

        $finalSql = $qb->getSQL();
        $this->assertEquals($initialSql, $finalSql);
    }

    public function testApplyThrowsExceptionForUnsafeOperator(): void
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
                'status' => ['DROP TABLE', 'value'],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe operator');

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);
    }

    public function testApplyWithNotInOperator(): void
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
                'status' => ['NOT IN', ['spam', 'deleted']],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('NOT IN', $sql);
    }

    public function testApplyWithInOperatorSingleValue(): void
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
                'status' => ['IN', 'approved'],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('IN', $sql);
    }

    public function testApplyWithEmptyInOperator(): void
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
                'status' => ['IN', []],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('1 = 0', $sql);
    }

    public function testApplyWithEmptyNotInOperator(): void
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
                'status' => ['NOT IN', []],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('1 = 1', $sql);
    }

    public function testApplyWithInOperatorIntegerList(): void
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
                'priority' => ['IN', [1, 2, 3]],
            ],
        ];

        $this->applier->apply($qb, 'p', 'id', $compiledRelations, $relationCriteria);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('EXISTS', $sql);
        $this->assertStringContainsString('IN', $sql);
    }
}
