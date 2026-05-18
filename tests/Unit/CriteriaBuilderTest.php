<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\Internal\CriteriaBuilder;
use Solo\BaseRepository\Relation\RelationKind;

class CriteriaBuilderTest extends TestCase
{
    private CriteriaBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CriteriaBuilder();
    }

    private function newQueryBuilder(): QueryBuilder
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        return $conn->createQueryBuilder();
    }

    /**
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function build(
        array $criteria,
        array $compiled = [],
        array $configured = [],
        bool $useAlias = true,
        ?string $locale = null,
    ): array {
        $qb = $this->newQueryBuilder();
        $expr = $this->builder->build($qb, $criteria, 't', 'id', $compiled, $configured, $useAlias, $locale);
        return [$expr, $qb->getParameters()];
    }

    // ── Leaves ───────────────────────────────────────────────────────────────

    public function testEmptyCriteriaReturnsNull(): void
    {
        [$expr, $params] = $this->build([]);
        $this->assertNull($expr);
        $this->assertSame([], $params);
    }

    public function testNullValueIsIsNull(): void
    {
        [$expr, $params] = $this->build(['email' => null]);
        $this->assertSame('t.email IS NULL', $expr);
        $this->assertSame([], $params);
    }

    public function testScalarValueIsEquality(): void
    {
        [$expr, $params] = $this->build(['email' => 'a@b']);
        $this->assertSame('t.email = :_cb_p1', $expr);
        $this->assertSame(['_cb_p1' => 'a@b'], $params);
    }

    public function testEmptyListValueProducesUnsatisfiable(): void
    {
        [$expr, $params] = $this->build(['id' => []]);
        $this->assertSame('1 = 0', $expr);
        $this->assertSame([], $params);
    }

    public function testSequentialListValueIsIn(): void
    {
        [$expr, $params] = $this->build(['id' => [1, 2, 3]]);
        $this->assertSame('t.id IN (:_cb_p1)', $expr);
        $this->assertSame([1, 2, 3], $params['_cb_p1']);
    }

    public function testNonSequentialIntKeyedListIsStillIn(): void
    {
        [$expr, $params] = $this->build(['id' => [0 => 5, 2 => 8]]);
        $this->assertSame('t.id IN (:_cb_p1)', $expr);
        $this->assertSame([5, 8], $params['_cb_p1']);
    }

    public function testQualifiedColumnIsNotPrefixed(): void
    {
        [$expr] = $this->build(['o.status' => 'paid']);
        $this->assertSame('o.status = :_cb_p1', $expr);
    }

    public function testUseAliasFalseSkipsAliasPrefix(): void
    {
        [$expr] = $this->build(['email' => 'x'], useAlias: false);
        $this->assertSame('email = :_cb_p1', $expr);
    }

    public function testMultipleCriteriaAreAndedAndWrapped(): void
    {
        [$expr] = $this->build(['email' => 'x', 'active' => 1]);
        $this->assertSame('(t.email = :_cb_p1 AND t.active = :_cb_p2)', $expr);
    }

    // ── Operator form ────────────────────────────────────────────────────────

    public static function comparisonOperatorProvider(): array
    {
        return [
            ['=', 't.x = :_cb_p1'],
            ['!=', 't.x != :_cb_p1'],
            ['<>', 't.x <> :_cb_p1'],
            ['<', 't.x < :_cb_p1'],
            ['>', 't.x > :_cb_p1'],
            ['<=', 't.x <= :_cb_p1'],
            ['>=', 't.x >= :_cb_p1'],
            ['LIKE', 't.x LIKE :_cb_p1'],
            ['NOT LIKE', 't.x NOT LIKE :_cb_p1'],
        ];
    }

    #[DataProvider('comparisonOperatorProvider')]
    public function testOperatorFormScalarOperators(string $operator, string $expectedSql): void
    {
        [$expr] = $this->build(['x' => [$operator => 5]]);
        $this->assertSame($expectedSql, $expr);
    }

    public function testOperatorEqWithNullIsIsNull(): void
    {
        [$expr] = $this->build(['x' => ['=' => null]]);
        $this->assertSame('t.x IS NULL', $expr);
    }

    public function testOperatorNotEqWithNullIsIsNotNull(): void
    {
        [$expr] = $this->build(['x' => ['!=' => null]]);
        $this->assertSame('t.x IS NOT NULL', $expr);
    }

    public function testOperatorLessThanNullThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->build(['x' => ['<' => null]]);
    }

    public function testOperatorInWithArrayValue(): void
    {
        [$expr, $params] = $this->build(['status' => ['IN' => ['a', 'b']]]);
        $this->assertSame('t.status IN (:_cb_p1)', $expr);
        $this->assertSame(['a', 'b'], $params['_cb_p1']);
    }

    public function testOperatorInWithScalarWrapsInList(): void
    {
        [$expr, $params] = $this->build(['status' => ['IN' => 'a']]);
        $this->assertSame('t.status IN (:_cb_p1)', $expr);
        $this->assertSame(['a'], $params['_cb_p1']);
    }

    public function testOperatorInWithEmptyListIsUnsatisfiable(): void
    {
        [$expr] = $this->build(['x' => ['IN' => []]]);
        $this->assertSame('1 = 0', $expr);
    }

    public function testOperatorNotInWithEmptyListIsTautology(): void
    {
        [$expr] = $this->build(['x' => ['NOT IN' => []]]);
        $this->assertSame('1 = 1', $expr);
    }

    public function testOperatorBetween(): void
    {
        [$expr, $params] = $this->build(['price' => ['BETWEEN' => [10, 20]]]);
        $this->assertSame('t.price BETWEEN :_cb_p1_min AND :_cb_p1_max', $expr);
        $this->assertSame(10, $params['_cb_p1_min']);
        $this->assertSame(20, $params['_cb_p1_max']);
    }

    public function testOperatorBetweenWrongCountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->build(['price' => ['BETWEEN' => [10]]]);
    }

    public function testScalarOperatorWithArrayValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->build(['x' => ['LIKE' => ['array', 'not allowed']]]);
    }

    public function testUnknownOperatorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->build(['x' => ['ILIKE' => 'foo']]);
    }

    // ── OR / AND groups ─────────────────────────────────────────────────────

    public function testOrGroup(): void
    {
        [$expr] = $this->build(['OR' => [['a' => 1], ['b' => 2]]]);
        $this->assertSame('(t.a = :_cb_p1 OR t.b = :_cb_p2)', $expr);
    }

    public function testAndGroup(): void
    {
        [$expr] = $this->build(['AND' => [['a' => 1], ['b' => 2]]]);
        $this->assertSame('(t.a = :_cb_p1 AND t.b = :_cb_p2)', $expr);
    }

    public function testSingleClauseGroupIsUnwrapped(): void
    {
        [$expr] = $this->build(['OR' => [['a' => 1]]]);
        $this->assertSame('t.a = :_cb_p1', $expr);
    }

    public function testNestedGroups(): void
    {
        [$expr] = $this->build([
            'OR' => [
                ['x' => 1],
                ['AND' => [['a' => 2], ['b' => 3]]],
            ],
        ]);
        $this->assertSame('(t.x = :_cb_p1 OR (t.a = :_cb_p2 AND t.b = :_cb_p3))', $expr);
    }

    public function testListFormSubCriteriaAreAndedTogether(): void
    {
        [$expr] = $this->build([
            'OR' => [
                ['a' => 1, 'b' => 2],
                ['c' => 3],
            ],
        ]);
        $this->assertSame('((t.a = :_cb_p1 AND t.b = :_cb_p2) OR t.c = :_cb_p3)', $expr);
    }

    public function testOrWithNonArrayValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->build(['OR' => 'not-an-array']);
    }

    public function testIntKeyWithNonArrayValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->build([0 => 'not-an-array']);
    }

    // ── Validators ──────────────────────────────────────────────────────────

    public function testUnsafeIdentifierThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->build(['x; DROP TABLE users' => 1]);
    }

    public function testBangPrefixWithoutDotThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->build(['!email' => 'x']);
    }

    // ── Order by ────────────────────────────────────────────────────────────

    public function testApplyOrderBy(): void
    {
        $qb = $this->newQueryBuilder()->select('*')->from('users', 't');
        $this->builder->applyOrderBy($qb, ['name' => 'ASC', 'id' => 'DESC'], 't');
        $this->assertStringContainsString('ORDER BY t.name ASC, t.id DESC', $qb->getSQL());
    }

    public function testApplyOrderByWithQualifiedColumnSkipsAlias(): void
    {
        $qb = $this->newQueryBuilder()->select('*')->from('users', 't');
        $this->builder->applyOrderBy($qb, ['o.created_at' => 'DESC'], 't');
        $this->assertStringContainsString('ORDER BY o.created_at DESC', $qb->getSQL());
    }

    public function testApplyOrderByDefaultsToAscOnUnknownDirection(): void
    {
        $qb = $this->newQueryBuilder()->select('*')->from('users', 't');
        $this->builder->applyOrderBy($qb, ['name' => 'sideways'], 't');
        $this->assertStringContainsString('ORDER BY t.name ASC', $qb->getSQL());
    }

    public function testApplyOrderByRejectsBangPrefix(): void
    {
        $qb = $this->newQueryBuilder()->select('*')->from('users', 't');
        $this->expectException(\InvalidArgumentException::class);
        $this->builder->applyOrderBy($qb, ['!field' => 'ASC'], 't');
    }

    // ── Relation EXISTS ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function tagsHasMany(): array
    {
        return [
            'tags' => [
                'kind' => RelationKind::HasMany,
                'foreignKey' => 'article_id',
                'relatedTable' => 'tags',
                'relatedPrimaryKey' => 'id',
            ],
        ];
    }

    public function testHasManyRelationProducesExists(): void
    {
        [$expr, $params] = $this->build(['tags.name' => 'php'], self::tagsHasMany());
        $this->assertSame(
            'EXISTS (SELECT 1 FROM tags _r1_tags WHERE _r1_tags.article_id = t.id AND _r1_tags.name = :_cb_p2)',
            $expr,
        );
        $this->assertSame('php', $params['_cb_p2']);
    }

    public function testNotExistsPrefix(): void
    {
        [$expr] = $this->build(['!tags.name' => 'php'], self::tagsHasMany());
        $this->assertStringStartsWith('NOT EXISTS', $expr);
    }

    public function testBelongsToReversesCorrelationDirection(): void
    {
        $compiled = [
            'category' => [
                'kind' => RelationKind::BelongsTo,
                'foreignKey' => 'category_id',
                'relatedTable' => 'categories',
                'relatedPrimaryKey' => 'id',
            ],
        ];
        [$expr] = $this->build(['category.name' => 'Tech'], $compiled);
        // belongsTo: r1.id = t.category_id (FK on base side)
        $this->assertStringContainsString('_r1_category.id = t.category_id', $expr);
    }

    public function testBelongsToManyJoinsThroughPivot(): void
    {
        $compiled = [
            'tags' => [
                'kind' => RelationKind::BelongsToMany,
                'relatedTable' => 'tags',
                'relatedPrimaryKey' => 'id',
                'pivotTable' => 'article_tag',
                'foreignPivotKey' => 'article_id',
                'relatedPivotKey' => 'tag_id',
            ],
        ];
        [$expr] = $this->build(['tags.name' => 'php'], $compiled);
        $this->assertStringContainsString(
            'FROM article_tag _r1_tags_p INNER JOIN tags _r1_tags ON _r1_tags.id = _r1_tags_p.tag_id',
            $expr,
        );
        $this->assertStringContainsString(
            '_r1_tags_p.article_id = t.id',
            $expr,
        );
    }

    public function testMultipleFieldsOnSameRelationShareOneExists(): void
    {
        [$expr] = $this->build(
            ['tags.name' => 'php', 'tags.active' => 1],
            self::tagsHasMany(),
        );
        $this->assertSame(1, substr_count($expr, 'EXISTS'));
        $this->assertStringContainsString('_r1_tags.name = :_cb_p2', $expr);
        $this->assertStringContainsString('_r1_tags.active = :_cb_p3', $expr);
    }

    public function testSelfRelationInUpdateContextThrows(): void
    {
        $compiled = [
            'children' => [
                'kind' => RelationKind::HasMany,
                'foreignKey' => 'parent_id',
                'relatedTable' => 't',  // same as base alias=table when useAlias=false
                'relatedPrimaryKey' => 'id',
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Self-referential EXISTS/');
        $this->build(['children.name' => 'x'], $compiled, useAlias: false);
    }

    public function testConfiguredButUncompiledRelationIsSilentlyDropped(): void
    {
        // Relation 'broken' is in $configured but NOT in $compiled — drop the criterion silently.
        [$expr, $params] = $this->build(
            ['broken.field' => 'value'],
            compiled: [],
            configured: ['broken' => true],
        );
        $this->assertNull($expr);
        $this->assertSame([], $params);
    }

    public function testUnknownRelationWithBangThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Unknown relation 'mystery'/");
        $this->build(['!mystery.field' => 'x']);
    }

    public function testUnknownRelationWithoutBangFallsThroughToQualifiedColumn(): void
    {
        // No relation 'foo' configured; treated as already-qualified column reference.
        [$expr] = $this->build(['foo.bar' => 1]);
        $this->assertSame('foo.bar = :_cb_p1', $expr);
    }

    // ── Translation in EXISTS ───────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function tagsHasManyWithTranslation(): array
    {
        return [
            'tags' => [
                'kind' => RelationKind::HasMany,
                'foreignKey' => 'article_id',
                'relatedTable' => 'tags',
                'relatedPrimaryKey' => 'id',
                'translation' => [
                    'table' => 'tag_translations',
                    'foreignKey' => 'tag_id',
                    'fields' => ['name', 'description'],
                ],
            ],
        ];
    }

    public function testTranslationJoinAddedWhenLocaleSet(): void
    {
        [$expr, $params] = $this->build(
            ['tags.name' => 'Привіт'],
            self::tagsHasManyWithTranslation(),
            locale: 'uk',
        );
        $this->assertStringContainsString(
            'LEFT JOIN tag_translations _r1_tags_t ON _r1_tags_t.tag_id = _r1_tags.id AND _r1_tags_t.locale = :_cb_loc2',
            $expr,
        );
        // Translated field 'name' targets translation alias, not relation alias.
        $this->assertStringContainsString('_r1_tags_t.name = :_cb_p3', $expr);
        $this->assertSame('uk', $params['_cb_loc2']);
        $this->assertSame('Привіт', $params['_cb_p3']);
    }

    public function testTranslationNotAppliedWithoutLocale(): void
    {
        [$expr] = $this->build(
            ['tags.name' => 'php'],
            self::tagsHasManyWithTranslation(),
            locale: null,
        );
        $this->assertStringNotContainsString('LEFT JOIN', $expr);
        $this->assertStringContainsString('_r1_tags.name = ', $expr);
    }

    public function testNonTranslatedFieldsKeepRelationAliasUnderTranslation(): void
    {
        // 'active' is not in translation['fields']; should still hit the relation alias.
        [$expr] = $this->build(
            ['tags.active' => 1, 'tags.name' => 'x'],
            self::tagsHasManyWithTranslation(),
            locale: 'en',
        );
        $this->assertStringContainsString('_r1_tags.active = ', $expr);
        $this->assertStringContainsString('_r1_tags_t.name = ', $expr);
    }

    public function testTranslationRewriteHandlesNestedOr(): void
    {
        [$expr] = $this->build(
            ['tags.OR' => [['name' => 'a'], ['name' => 'b']]],
            self::tagsHasManyWithTranslation(),
            locale: 'uk',
        );
        // Both branches should target translation alias inside the OR.
        $this->assertSame(2, substr_count($expr, '_r1_tags_t.name'));
    }
}
