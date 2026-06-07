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

    public function testApplyJoinWithFallbackAddsSecondJoinAndCoalesces(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name', 'description']);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $conn->createQueryBuilder()->select('p.*')->from('products', 'p');

        $service->applyJoin($qb, 'p', 'id', 'ru', 'uk');

        $sql = $qb->getSQL();
        $this->assertStringContainsString('LEFT JOIN product_translations tr_fb', $sql);
        $this->assertStringContainsString('tr_fb.product_id = p.id', $sql);
        $this->assertStringContainsString('tr_fb.locale = :tr_fb_locale', $sql);
        $this->assertStringContainsString("COALESCE(NULLIF(tr.name, ''), tr_fb.name) AS name", $sql);
        $this->assertStringContainsString("COALESCE(NULLIF(tr.description, ''), tr_fb.description) AS description", $sql);
        $this->assertSame('ru', $qb->getParameter('tr_locale'));
        $this->assertSame('uk', $qb->getParameter('tr_fb_locale'));
    }

    public function testApplyJoinWithFallbackEqualToLocaleSkipsSecondJoin(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name']);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $qb = $conn->createQueryBuilder()->select('p.*')->from('products', 'p');

        $service->applyJoin($qb, 'p', 'id', 'uk', 'uk');

        $sql = $qb->getSQL();
        $this->assertStringNotContainsString('tr_fb', $sql);
        $this->assertStringNotContainsString('COALESCE', $sql);
        $this->assertStringContainsString('tr.name', $sql);
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
