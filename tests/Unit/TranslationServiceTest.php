<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\TranslationService;

class TranslationServiceTest extends TestCase
{
    private function createQueryBuilder()
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        return $connection->createQueryBuilder()->select('p.*')->from('products', 'p');
    }

    public function testHasLocaleReturnsFalseByDefault(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name', 'description']);

        $this->assertFalse($service->hasLocale());
    }

    public function testSetLocaleAndHasLocale(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name']);

        $service->setLocale('uk');
        $this->assertTrue($service->hasLocale());
    }

    public function testResetClearsLocale(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name']);

        $service->setLocale('uk');
        $this->assertTrue($service->hasLocale());

        $service->reset();
        $this->assertFalse($service->hasLocale());
    }

    public function testApplyJoinDoesNothingWithoutLocale(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name']);
        $qb = $this->createQueryBuilder();

        $service->applyJoin($qb, 'p', 'id');

        $this->assertStringNotContainsString('product_translations', $qb->getSQL());
    }

    public function testApplyJoinAddsLeftJoinAndFields(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name', 'description']);
        $service->setLocale('uk');

        $qb = $this->createQueryBuilder();
        $service->applyJoin($qb, 'p', 'id');

        $sql = $qb->getSQL();
        $this->assertStringContainsString('LEFT JOIN product_translations tr', $sql);
        $this->assertStringContainsString('tr.product_id = p.id', $sql);
        $this->assertStringContainsString('tr.locale = :tr_locale', $sql);
        $this->assertStringContainsString('tr.name', $sql);
        $this->assertStringContainsString('tr.description', $sql);
    }

    public function testApplyJoinSetsLocaleParameter(): void
    {
        $service = new TranslationService('product_translations', 'product_id', ['name']);
        $service->setLocale('en');

        $qb = $this->createQueryBuilder();
        $service->applyJoin($qb, 'p', 'id');

        $this->assertEquals('en', $qb->getParameter('tr_locale'));
    }

    public function testConstructorValidatesForeignKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        new TranslationService('translations', 'product id; DROP TABLE', ['name']);
    }

    public function testConstructorValidatesFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier');

        new TranslationService('translations', 'product_id', ['name', '1=1; --']);
    }

    public function testConstructorAcceptsValidIdentifiers(): void
    {
        $service = new TranslationService(
            'product_translations',
            'product_id',
            ['name', 'description', 'h1', 'meta_title', 'meta_description']
        );

        $this->assertFalse($service->hasLocale());
    }
}