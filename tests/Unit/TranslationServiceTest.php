<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\TranslationService;

class TranslationServiceTest extends TestCase
{
    public function testApplyJoinDoesNothingWithoutLocale(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name']);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $connection->createQueryBuilder()->select('p.*')->from('products', 'p');

        $service->applyJoin($qb, 'p', 'id');

        $this->assertStringNotContainsString('product_translations', $qb->getSQL());
    }

    public function testConstructorValidatesForeignKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TranslationService('translations', 'product id; DROP TABLE', ['name']);
    }

    public function testConstructorValidatesFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TranslationService('translations', 'product_id', ['name', '1=1; --']);
    }
}
