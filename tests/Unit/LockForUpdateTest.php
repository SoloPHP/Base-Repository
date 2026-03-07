<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;

class LockForUpdateTest extends TestCase
{
    private function createMockConnection(): Connection&\PHPUnit\Framework\MockObject\MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')
            ->willReturn(
                (new \ReflectionClass(\Doctrine\DBAL\Query\QueryBuilder::class))
                    ->newInstanceWithoutConstructor()
            );

        return $connection;
    }

    public function testLockForUpdateSingleId(): void
    {
        $connection = $this->createMockConnection();

        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                'SELECT id FROM users WHERE id = ? FOR UPDATE',
                [42]
            );

        $repo = new class($connection) extends BaseRepository {
            public function __construct(Connection $connection)
            {
                parent::__construct($connection, \stdClass::class, 'users');
            }
        };

        $repo->lockForUpdate(42);
    }

    public function testLockForUpdateMultipleIds(): void
    {
        $connection = $this->createMockConnection();

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                'SELECT id FROM users WHERE id IN (?,?,?) FOR UPDATE',
                [1, 2, 3]
            );

        $repo = new class($connection) extends BaseRepository {
            public function __construct(Connection $connection)
            {
                parent::__construct($connection, \stdClass::class, 'users');
            }
        };

        $repo->lockForUpdate([1, 2, 3]);
    }

    public function testLockForUpdateEmptyArray(): void
    {
        $connection = $this->createMockConnection();

        $connection->expects($this->never())->method('fetchOne');
        $connection->expects($this->never())->method('fetchAllAssociative');

        $repo = new class($connection) extends BaseRepository {
            public function __construct(Connection $connection)
            {
                parent::__construct($connection, \stdClass::class, 'users');
            }
        };

        $repo->lockForUpdate([]);
    }

    public function testLockForUpdateStringId(): void
    {
        $connection = $this->createMockConnection();

        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                'SELECT id FROM users WHERE id = ? FOR UPDATE',
                ['uuid-123']
            );

        $repo = new class($connection) extends BaseRepository {
            public function __construct(Connection $connection)
            {
                parent::__construct($connection, \stdClass::class, 'users');
            }
        };

        $repo->lockForUpdate('uuid-123');
    }
}
