<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\TranslationService;

class TranslationServiceTest extends TestCase
{
    public function testApplyJoinAddsLeftJoinAndSelects(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name', 'description']);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $conn->createQueryBuilder()->select('p.*')->from('products', 'p');

        $service->applyJoin($qb, 'p', 'id', 'uk');

        $sql = $qb->getSQL();
        $this->assertStringContainsString('LEFT JOIN product_translations tr', $sql);
        $this->assertStringContainsString('tr.product_id = p.id', $sql);
        $this->assertStringContainsString('tr.locale = :tr_locale', $sql);
        $this->assertStringContainsString('tr.name', $sql);
        $this->assertStringContainsString('tr.description', $sql);
        $this->assertSame('uk', $qb->getParameter('tr_locale'));
    }

    public function testConstructorRejectsUnsafeForeignKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TranslationService('translations', 'product id; DROP TABLE', ['name']);
    }

    public function testConstructorRejectsUnsafeFieldNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TranslationService('translations', 'product_id', ['name', '1=1; --']);
    }
}
