<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\ModelMapper;

class ModelMapperTest extends TestCase
{
    public function testMapThrowsExceptionWhenMethodNotCallable(): void
    {
        $mapper = new ModelMapper(\stdClass::class, 'nonExistentMethod');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not callable');

        $mapper->map(['id' => 1]);
    }
}
