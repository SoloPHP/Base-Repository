<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\HasMany;

class ScopesTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private ScopeItemRepository $itemRepository;
    private ScopeEntryRepository $entryRepository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE scope_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                amount INTEGER NOT NULL DEFAULT 0,
                status VARCHAR(50) NOT NULL DEFAULT "new",
                deleted_at TIMESTAMP NULL DEFAULT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE scope_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_id INTEGER NOT NULL,
                qty INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->entryRepository = new ScopeEntryRepository($this->connection);
        $this->itemRepository = new ScopeItemRepository($this->connection, $this->entryRepository);
    }

    /**
     * Seeds:
     *   A — own amount > 0, no entries        (matches 'nonzero')
     *   B — amount 0, entry qty 3, archived   (matches 'nonzero' via relation)
     *   C — amount 0, no entries
     *   D — amount 0, entry qty 0
     */
    private function seedItems(): void
    {
        $this->itemRepository->create(['name' => 'A', 'amount' => 5, 'status' => 'new']);
        $b = $this->itemRepository->create(['name' => 'B', 'amount' => 0, 'status' => 'archived']);
        $this->itemRepository->create(['name' => 'C', 'amount' => 0, 'status' => 'new']);
        $d = $this->itemRepository->create(['name' => 'D', 'amount' => 0, 'status' => 'new']);

        $this->entryRepository->create(['item_id' => $b->id, 'qty' => 3]);
        $this->entryRepository->create(['item_id' => $d->id, 'qty' => 0]);
    }

    /** @return list<string> */
    private function names(array $items): array
    {
        $names = array_map(fn(ScopeItem $i) => $i->name, $items);
        sort($names);
        return $names;
    }

    // ── Expansion behavior ───────────────────────────────────────────────

    public function testScopeExpandsToCondition(): void
    {
        $this->seedItems();

        $items = $this->itemRepository->findBy(['nonzero' => true]);

        $this->assertSame(['A', 'B'], $this->names($items));
    }

    public function testScopeAcceptsHttpStringValues(): void
    {
        $this->seedItems();

        $this->assertCount(2, $this->itemRepository->findBy(['nonzero' => '1']));
        $this->assertCount(4, $this->itemRepository->findBy(['nonzero' => '0']));
    }

    public function testFalsyScopeValueAddsNoConditionButConsumesKey(): void
    {
        $this->seedItems();

        // The scope key never reaches SQL: no "unknown column nonzero" error.
        $this->assertCount(4, $this->itemRepository->findBy(['nonzero' => false]));
        $this->assertCount(4, $this->itemRepository->findBy(['nonzero' => null]));
    }

    public function testScopeComposesWithRegularCriteria(): void
    {
        $this->seedItems();

        $items = $this->itemRepository->findBy(['status' => 'new', 'nonzero' => true]);

        $this->assertSame(['A'], $this->names($items));
    }

    public function testParameterizedScope(): void
    {
        $this->seedItems();

        $this->assertCount(1, $this->itemRepository->findBy(['min_amount' => '3']));

        $items = $this->itemRepository->findBy(['min_amount' => 3, 'nonzero' => true]);
        $this->assertSame(['A'], $this->names($items));
    }

    public function testScopeWorksInCountAndExists(): void
    {
        $this->seedItems();

        $this->assertSame(2, $this->itemRepository->count(['nonzero' => true]));
        $this->assertTrue($this->itemRepository->exists(['nonzero' => true]));
        $this->assertFalse($this->itemRepository->exists(['nonzero' => true, 'amount' => ['>' => 100]]));
    }

    public function testScopeWorksInUpdateBy(): void
    {
        $this->seedItems();

        // updateBy compiles criteria without a table alias (useAlias = false).
        $affected = $this->itemRepository->updateBy(['nonzero' => true], ['status' => 'checked']);

        $this->assertSame(2, $affected);
        $this->assertSame(['A', 'B'], $this->names($this->itemRepository->findBy(['status' => 'checked'])));
    }

    public function testListFormEntryWithIntMaxKeySurvivesExpansion(): void
    {
        $this->seedItems();

        // array_merge-based fragment appending must not fatal on extreme int keys.
        $items = $this->itemRepository->findBy([PHP_INT_MAX => ['name' => 'A'], 'nonzero' => '1']);

        $this->assertSame(['A'], $this->names($items));
    }

    // ── Write safety ─────────────────────────────────────────────────────

    public function testWriteRefusedWhenScopesCollapseCriteriaToEmpty(): void
    {
        $this->seedItems();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('refusing an unbounded write');

        // 'nonzero' => false expands to no condition; with nothing left this
        // would have been an UPDATE without WHERE.
        $this->itemRepository->updateBy(['nonzero' => false], ['status' => 'oops']);
    }

    public function testDeleteByRefusedWhenScopesCollapseCriteriaToEmpty(): void
    {
        $this->seedItems();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('refusing an unbounded write');

        $this->itemRepository->deleteBy(['nonzero' => '0']);
    }

    public function testExplicitlyEmptyCriteriaWriteIsStillAllowed(): void
    {
        $this->seedItems();

        $affected = $this->itemRepository->updateBy([], ['status' => 'bulk']);

        $this->assertSame(4, $affected);
    }

    // ── Scope definition validation ──────────────────────────────────────

    public function testReservedGroupKeyScopeNameThrows(): void
    {
        $repository = new CustomScopesRepository($this->connection, ['OR' => fn($value) => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Scope name 'OR' collides with a reserved criteria keyword");

        $repository->findBy([]);
    }

    public function testOperatorWordScopeNameThrows(): void
    {
        $repository = new CustomScopesRepository($this->connection, ['like' => fn($value) => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Scope name 'like' collides with a reserved criteria keyword");

        $repository->findBy([]);
    }

    public function testPrimaryKeyScopeNameThrows(): void
    {
        $repository = new CustomScopesRepository($this->connection, ['id' => fn($value) => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Scope name 'id' collides with the primary key column");

        $repository->findBy([]);
    }

    public function testSoftDeleteColumnScopeNameThrows(): void
    {
        $repository = new CustomScopesRepository(
            $this->connection,
            ['deleted_at' => fn($value) => null],
            'deleted_at',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Scope name 'deleted_at' collides with the soft-delete column");

        $repository->findBy([]);
    }

    public function testNonStringScopeNameThrows(): void
    {
        $repository = new CustomScopesRepository($this->connection, ['0' => fn($value) => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Scope names must be non-empty strings');

        $repository->findBy([]);
    }

    public function testDottedScopeNameThrows(): void
    {
        $repository = new CustomScopesRepository($this->connection, ['entries.qty' => fn($value) => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe identifier: entries.qty');

        $repository->findBy([]);
    }

    public function testNonCallableScopeHandlerThrows(): void
    {
        $repository = new CustomScopesRepository($this->connection, ['valid_name' => 'not-a-callable']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Scope 'valid_name' handler must be callable");

        $repository->findBy([]);
    }

    public function testNonArrayFragmentThrowsNamingTheScope(): void
    {
        $this->seedItems();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Scope 'broken' handler must return array or null, got bool");

        $this->itemRepository->findBy(['broken' => 1]);
    }

    // ── Soft delete interplay ────────────────────────────────────────────

    public function testSoftDeleteFilterSurvivesScopeExpansion(): void
    {
        $repository = new CustomScopesRepository(
            $this->connection,
            ['nonzero' => fn($value) => $value ? ['amount' => ['>' => 0]] : null],
            'deleted_at',
        );

        $repository->create(['name' => 'A', 'amount' => 5]);
        $b = $repository->create(['name' => 'B', 'amount' => 3]);
        $repository->create(['name' => 'C', 'amount' => 0]);
        $repository->delete($b->id); // soft delete

        $items = $repository->findBy(['nonzero' => true]);

        $this->assertSame(['A'], $this->names($items));
    }

    public function testShowAllSentinelWorksAlongsideScopes(): void
    {
        $repository = new CustomScopesRepository(
            $this->connection,
            ['nonzero' => fn($value) => $value ? ['amount' => ['>' => 0]] : null],
            'deleted_at',
        );

        $repository->create(['name' => 'A', 'amount' => 5]);
        $b = $repository->create(['name' => 'B', 'amount' => 3]);
        $repository->delete($b->id); // soft delete

        // Top-level '*' must keep its show-all meaning while the scope expands.
        $items = $repository->findBy(['nonzero' => true, 'deleted_at' => '*']);

        $this->assertSame(['A', 'B'], $this->names($items));
    }

    // ── Filter-only relations & eager loading ───────────────────────────

    public function testFilterOnlyRelationUsableInCriteria(): void
    {
        $this->seedItems();

        // Dot-notation EXISTS works without a setter on the relation.
        $items = $this->itemRepository->findBy(['entries.qty' => ['>' => 0]]);

        $this->assertSame(['B'], $this->names($items));
    }

    public function testWithThrowsImmediatelyForFilterOnlyRelation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Relation 'entries' is filter-only");

        $this->itemRepository->with(['entries']);
    }

    public function testFailedWithLeavesNoEagerStateBehind(): void
    {
        $this->seedItems();

        try {
            $this->itemRepository->with(['entries']);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // expected
        }

        // The rejected with() must not poison subsequent plain queries.
        $this->assertCount(4, $this->itemRepository->findBy([]));
    }

    public function testEagerLoadingCriteriaAreImmuneToRelatedRepoScopes(): void
    {
        $this->seedItems();

        // ScopeEntryRepository defines a scope named 'item_id' (the FK column);
        // the eager loader's machine-generated criteria must not activate it.
        $items = $this->itemRepository->with(['loadedEntries'])->findBy([]);

        $byName = [];
        foreach ($items as $item) {
            $byName[$item->name] = $item;
        }

        $this->assertCount(1, $byName['B']->entries);
        $this->assertCount(1, $byName['D']->entries);
        $this->assertCount(0, $byName['A']->entries);
    }
}

// Models
class ScopeItem
{
    /** @var list<ScopeEntry> */
    public array $entries = [];

    public function __construct(
        public int $id,
        public string $name,
        public int $amount,
        public string $status
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['name'],
            (int) $data['amount'],
            $data['status'] ?? 'new'
        );
    }

    public function setEntries(array $entries): void
    {
        $this->entries = $entries;
    }
}

class ScopeEntry
{
    public function __construct(
        public int $id,
        public int $item_id,
        public int $qty
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], (int) $data['item_id'], (int) $data['qty']);
    }
}

// Repositories
class ScopeEntryRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, ScopeEntry::class, 'scope_entries');
    }

    protected function scopes(): array
    {
        return [
            // Named after the FK column on purpose: eager-loading machine
            // criteria must never activate it (they are list-form wrapped).
            'item_id' => fn($value) => ['qty' => ['>' => 999]],
        ];
    }
}

class ScopeItemRepository extends BaseRepository
{
    protected array $relationConfig = [];

    public ScopeEntryRepository $entryRepository;

    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        ScopeEntryRepository $entryRepository
    ) {
        $this->entryRepository = $entryRepository;
        $this->relationConfig = [
            // Filter-only relation: no setter, usable in criteria but not eager-loadable.
            'entries' => new HasMany(
                repository: 'entryRepository',
                foreignKey: 'item_id',
            ),
            // Same relation with a setter, for eager-loading tests.
            'loadedEntries' => new HasMany(
                repository: 'entryRepository',
                foreignKey: 'item_id',
                setter: 'setEntries',
            ),
        ];
        parent::__construct($connection, ScopeItem::class, 'scope_items');
    }

    protected function scopes(): array
    {
        return [
            // Boolean toggle expanding to an OR group with a relation EXISTS.
            'nonzero' => fn($value) => filter_var($value, FILTER_VALIDATE_BOOL)
                ? ['OR' => [
                      'amount' => ['>' => 0],
                      'entries.qty' => ['>' => 0],
                  ]]
                : null,
            // Parameterized scope: the raw value feeds the fragment.
            'min_amount' => fn($value) => $value === null
                ? null
                : ['amount' => ['>=' => (int) $value]],
            // Violates the array|null return contract; must throw when activated.
            'broken' => fn($value) => true,
        ];
    }
}

/**
 * Repository with constructor-injected scope definitions, for validation tests.
 */
class CustomScopesRepository extends BaseRepository
{
    /**
     * @param array<int|string, mixed> $customScopes
     */
    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        private readonly array $customScopes,
        ?string $deletedAtColumn = null
    ) {
        $this->deletedAtColumn = $deletedAtColumn;
        parent::__construct($connection, ScopeItem::class, 'scope_items');
    }

    protected function scopes(): array
    {
        return $this->customScopes;
    }
}
