<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\HasMany;

/**
 * Coverage for the EXISTS-time translation join: filtering parent rows by a
 * translated field on a related repository when withLocale() is active.
 */
class RelationCriteriaTranslationTest extends TestCase
{
    private Connection $connection;
    private TranslatedArticleRepository $articles;
    private UserWithArticlesRepository $users;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement('
            CREATE TABLE users_t (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(255) NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE articles_t (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                slug VARCHAR(255) NOT NULL
            )
        ');
        $this->connection->executeStatement('
            CREATE TABLE article_t_translations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                article_id INTEGER NOT NULL,
                locale VARCHAR(5) NOT NULL,
                title VARCHAR(255),
                body TEXT,
                UNIQUE(article_id, locale)
            )
        ');

        $this->articles = new TranslatedArticleRepository($this->connection);
        $this->users = new UserWithArticlesRepository($this->connection, $this->articles);

        $this->connection->insert('users_t', ['email' => 'alice@x.com']);
        $this->connection->insert('users_t', ['email' => 'bob@x.com']);

        $this->connection->insert('articles_t', ['user_id' => 1, 'slug' => 'hello']);
        $this->connection->insert('articles_t', ['user_id' => 2, 'slug' => 'foo']);

        // Article 1: 'Привіт' (uk) / 'Hello' (en)
        // Article 2: 'Бар' (uk) only
        $this->connection->insert('article_t_translations', ['article_id' => 1, 'locale' => 'uk', 'title' => 'Привіт', 'body' => 'Тіло']);
        $this->connection->insert('article_t_translations', ['article_id' => 1, 'locale' => 'en', 'title' => 'Hello',  'body' => 'Body']);
        $this->connection->insert('article_t_translations', ['article_id' => 2, 'locale' => 'uk', 'title' => 'Бар',    'body' => 'Foo']);
    }

    public function testRelationCriteriaOnTranslatedFieldUsesLocale(): void
    {
        $found = $this->users->withLocale('uk')->findBy(['articles.title' => 'Привіт']);
        $this->assertCount(1, $found);
        $this->assertSame('alice@x.com', $found[0]->email);
    }

    public function testDifferentLocalePicksDifferentRow(): void
    {
        $found = $this->users->withLocale('en')->findBy(['articles.title' => 'Hello']);
        $this->assertCount(1, $found);
        $this->assertSame('alice@x.com', $found[0]->email);
    }

    public function testLocaleWithMissingTranslationYieldsNoMatch(): void
    {
        // Article 2 has no 'en' translation row; LEFT JOIN nulls out title; equality fails.
        $found = $this->users->withLocale('en')->findBy(['articles.title' => 'Бар']);
        $this->assertSame([], $found);
    }

    public function testNotExistsWithTranslatedField(): void
    {
        // bob has no article matching 'Привіт' in uk → bob is in the NOT EXISTS result.
        $found = $this->users->withLocale('uk')->findBy(['!articles.title' => 'Привіт']);
        $this->assertCount(1, $found);
        $this->assertSame('bob@x.com', $found[0]->email);
    }

    public function testNonTranslatedFieldKeepsRelationAlias(): void
    {
        // 'slug' is not a translated field; should resolve against articles_t directly even with a locale set.
        $found = $this->users->withLocale('uk')->findBy(['articles.slug' => 'foo']);
        $this->assertCount(1, $found);
        $this->assertSame('bob@x.com', $found[0]->email);
    }

    public function testTranslatedAndNonTranslatedFieldsInSameExists(): void
    {
        $found = $this->users->withLocale('uk')->findBy([
            'articles.title' => 'Привіт',
            'articles.slug' => 'hello',
        ]);
        $this->assertCount(1, $found);
    }

    public function testOrGroupWithTranslatedFieldsRewritesAllBranches(): void
    {
        $found = $this->users->withLocale('uk')->findBy([
            'articles.OR' => [
                ['title' => 'Привіт'],
                ['title' => 'Бар'],
            ],
        ], ['id' => 'ASC']);
        $this->assertSame(['alice@x.com', 'bob@x.com'], array_map(fn($u) => $u->email, $found));
    }

    public function testLocaleDoesNotLeakIntoNextQuery(): void
    {
        // First query consumes the locale.
        $this->users->withLocale('uk')->findBy([]);

        // Second query has no locale set; relation criteria on translated field
        // must not silently apply translation — articles_t has no 'title' column.
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->users->findBy(['articles.title' => 'Привіт']);
    }

    public function testWithoutLocaleClearsLocale(): void
    {
        $this->users->withLocale('uk')->withoutLocale();
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->users->findBy(['articles.title' => 'Привіт']);
    }

    public function testSelfRelationInUpdateByThrows(): void
    {
        // categories.parent_id self-relation; updateBy with relation criteria
        // would put the target table inside a subquery FROM (forbidden on MySQL).
        $this->connection->executeStatement('
            CREATE TABLE categories_sr (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NULL,
                name VARCHAR(50)
            )
        ');
        $repo = new SelfRelatedCategoryRepository($this->connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Self-referential EXISTS/');
        $repo->updateBy(['children.name' => 'x'], ['name' => 'y']);
    }
}

// ── fixtures ────────────────────────────────────────────────────────────────

class TranslatedArticle
{
    public function __construct(
        public int $id,
        public int $user_id,
        public string $slug,
        public ?string $title = null,
        public ?string $body = null,
    ) {
    }

    public static function fromArray(array $r): self
    {
        return new self(
            (int) $r['id'],
            (int) $r['user_id'],
            $r['slug'],
            $r['title'] ?? null,
            $r['body'] ?? null,
        );
    }
}

class TranslatedArticleRepository extends BaseRepository
{
    protected ?array $translationConfig = [
        'table' => 'article_t_translations',
        'foreignKey' => 'article_id',
        'fields' => ['title', 'body'],
    ];

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, TranslatedArticle::class, 'articles_t', 'a');
    }
}

class UserWithArticles
{
    public function __construct(public int $id, public string $email)
    {
    }

    public static function fromArray(array $r): self
    {
        return new self((int) $r['id'], $r['email']);
    }
}

class UserWithArticlesRepository extends BaseRepository
{
    public function __construct(Connection $connection, public TranslatedArticleRepository $articles)
    {
        $this->relationConfig = [
            'articles' => new HasMany('articles', 'user_id', 'setArticles'),
        ];
        parent::__construct($connection, UserWithArticles::class, 'users_t', 'u');
    }
}

class SelfCategory
{
    public function __construct(public int $id, public ?int $parent_id, public string $name)
    {
    }

    public static function fromArray(array $r): self
    {
        return new self((int) $r['id'], $r['parent_id'] ?? null, $r['name']);
    }
}

class SelfRelatedCategoryRepository extends BaseRepository
{
    public SelfRelatedCategoryRepository $self;

    public function __construct(Connection $connection)
    {
        $this->self = $this;
        $this->relationConfig = [
            'children' => new HasMany('self', 'parent_id', 'setChildren'),
        ];
        parent::__construct($connection, SelfCategory::class, 'categories_sr');
    }
}
