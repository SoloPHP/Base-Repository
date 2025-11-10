<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\ModelMapper;

class ModelMapperTest extends TestCase
{
    public function testMapWithFromArrayMethod(): void
    {
        $mapper = new ModelMapper(TestModel::class, 'fromArray');

        $row = ['id' => 1, 'name' => 'Test'];
        $result = $mapper->map($row);

        $this->assertInstanceOf(TestModel::class, $result);
        $this->assertEquals(1, $result->id);
        $this->assertEquals('Test', $result->name);
    }

    public function testMapWithCustomMethod(): void
    {
        $mapper = new ModelMapper(TestModel::class, 'create');

        $row = ['id' => 2, 'name' => 'Custom'];
        $result = $mapper->map($row);

        $this->assertInstanceOf(TestModel::class, $result);
        $this->assertEquals(2, $result->id);
        $this->assertEquals('Custom', $result->name);
    }

    public function testMapThrowsExceptionWhenMethodNotCallable(): void
    {
        $mapper = new ModelMapper(TestModel::class, 'nonExistentMethod');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not callable');

        $mapper->map(['id' => 1]);
    }
}

// Test model class
class TestModel
{
    public function __construct(
        public int $id,
        public string $name
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name']);
    }

    public static function create(array $data): self
    {
        return new self($data['id'], $data['name']);
    }
}

