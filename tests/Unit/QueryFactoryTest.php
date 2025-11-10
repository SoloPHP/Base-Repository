<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\QueryFactory;

class QueryFactoryTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    public function testBuilder(): void
    {
        $factory = new QueryFactory($this->connection, 'users', 'u');
        $qb = $factory->builder();

        $this->assertInstanceOf(\Doctrine\DBAL\Query\QueryBuilder::class, $qb);
    }

    public function testTableSelectAll(): void
    {
        $factory = new QueryFactory($this->connection, 'users', 'u');
        $qb = $factory->tableSelectAll();

        $sql = $qb->getSQL();
        $this->assertStringContainsString('SELECT *', $sql);
        $this->assertStringContainsString('FROM users', $sql);
        $this->assertStringContainsString('u', $sql);
    }

    public function testInsertBuilder(): void
    {
        $factory = new QueryFactory($this->connection, 'users', 'u');
        $qb = $factory->insertBuilder();

        $sql = $qb->getSQL();
        $this->assertStringContainsString('INSERT INTO users', $sql);
    }

    public function testUpdateByIdBuilder(): void
    {
        $factory = new QueryFactory($this->connection, 'users', 'u');
        $qb = $factory->updateByIdBuilder('id', 1);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('UPDATE users', $sql);
        $this->assertStringContainsString('id = :id', $sql);
    }

    public function testDeleteByIdBuilder(): void
    {
        $factory = new QueryFactory($this->connection, 'users', 'u');
        $qb = $factory->deleteByIdBuilder('id', 1);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('DELETE FROM users', $sql);
        $this->assertStringContainsString('id = :id', $sql);
    }
}

