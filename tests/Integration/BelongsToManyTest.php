<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\BelongsToMany;

class BelongsToManyTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private ArticleWithTagsRepository $articleRepository;
    private TagModelRepository $tagRepository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE articles_m2m (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE tags_m2m (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                active INTEGER DEFAULT 1
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE article_tag (
                article_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (article_id, tag_id)
            )
        ');

        $this->tagRepository = new TagModelRepository($this->connection);
        $this->articleRepository = new ArticleWithTagsRepository(
            $this->connection,
            $this->tagRepository
        );
    }

    public function testWithBelongsToMany(): void
    {
        $article = $this->articleRepository->create(['title' => 'Test Article']);
        $tag1 = $this->tagRepository->create(['name' => 'php', 'active' => 1]);
        $tag2 = $this->tagRepository->create(['name' => 'laravel', 'active' => 1]);

        // Create pivot records
        $this->connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $tag1->id]);
        $this->connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $tag2->id]);

        $foundArticle = $this->articleRepository->with(['tags'])->find($article->id);

        $this->assertNotNull($foundArticle);
        $this->assertCount(2, $foundArticle->tags);
        $this->assertEquals('php', $foundArticle->tags[0]->name);
        $this->assertEquals('laravel', $foundArticle->tags[1]->name);
    }

    public function testWithBelongsToManyOnFindBy(): void
    {
        $article1 = $this->articleRepository->create(['title' => 'Article 1']);
        $article2 = $this->articleRepository->create(['title' => 'Article 2']);
        $tag1 = $this->tagRepository->create(['name' => 'php', 'active' => 1]);
        $tag2 = $this->tagRepository->create(['name' => 'javascript', 'active' => 1]);

        $this->connection->insert('article_tag', ['article_id' => $article1->id, 'tag_id' => $tag1->id]);
        $this->connection->insert('article_tag', ['article_id' => $article1->id, 'tag_id' => $tag2->id]);
        $this->connection->insert('article_tag', ['article_id' => $article2->id, 'tag_id' => $tag2->id]);

        $articles = $this->articleRepository->with(['tags'])->findBy([]);

        $this->assertCount(2, $articles);
        $this->assertCount(2, $articles[0]->tags);
        $this->assertCount(1, $articles[1]->tags);
    }

    public function testWithBelongsToManyReturnsEmptyArrayWhenNoRelated(): void
    {
        $article = $this->articleRepository->create(['title' => 'No Tags Article']);

        $foundArticle = $this->articleRepository->with(['tags'])->find($article->id);

        $this->assertNotNull($foundArticle);
        $this->assertIsArray($foundArticle->tags);
        $this->assertEmpty($foundArticle->tags);
    }

    public function testFindByWithBelongsToManyCriteria(): void
    {
        $article1 = $this->articleRepository->create(['title' => 'PHP Article']);
        $article2 = $this->articleRepository->create(['title' => 'JS Article']);
        $article3 = $this->articleRepository->create(['title' => 'No Tags']);
        $tag1 = $this->tagRepository->create(['name' => 'php', 'active' => 1]);
        $tag2 = $this->tagRepository->create(['name' => 'javascript', 'active' => 1]);

        $this->connection->insert('article_tag', ['article_id' => $article1->id, 'tag_id' => $tag1->id]);
        $this->connection->insert('article_tag', ['article_id' => $article2->id, 'tag_id' => $tag2->id]);

        // Find articles that have tag 'php'
        $articles = $this->articleRepository->findBy(['tags.name' => 'php']);

        $this->assertCount(1, $articles);
        $this->assertEquals('PHP Article', $articles[0]->title);
    }

    public function testFindByWithNotExistsBelongsToManyCriteria(): void
    {
        $article1 = $this->articleRepository->create(['title' => 'With Tags']);
        $article2 = $this->articleRepository->create(['title' => 'Without Tags']);
        $tag = $this->tagRepository->create(['name' => 'php', 'active' => 1]);

        $this->connection->insert('article_tag', ['article_id' => $article1->id, 'tag_id' => $tag->id]);

        // Find articles that DON'T have active tags
        $articles = $this->articleRepository->findBy(['!tags.active' => 1]);

        $this->assertCount(1, $articles);
        $this->assertEquals('Without Tags', $articles[0]->title);
    }

    public function testCountWithBelongsToManyCriteria(): void
    {
        $article1 = $this->articleRepository->create(['title' => 'Article 1']);
        $article2 = $this->articleRepository->create(['title' => 'Article 2']);
        $tag = $this->tagRepository->create(['name' => 'php', 'active' => 1]);

        $this->connection->insert('article_tag', ['article_id' => $article1->id, 'tag_id' => $tag->id]);

        $count = $this->articleRepository->count(['tags.name' => 'php']);

        $this->assertEquals(1, $count);
    }

    public function testExistsWithBelongsToManyCriteria(): void
    {
        $article = $this->articleRepository->create(['title' => 'Article']);
        $tag = $this->tagRepository->create(['name' => 'php', 'active' => 1]);

        $this->connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $tag->id]);

        $this->assertTrue($this->articleRepository->exists(['tags.name' => 'php']));
        $this->assertFalse($this->articleRepository->exists(['tags.name' => 'ruby']));
    }

    public function testFindByWithBelongsToManyInList(): void
    {
        $article1 = $this->articleRepository->create(['title' => 'Article 1']);
        $article2 = $this->articleRepository->create(['title' => 'Article 2']);
        $article3 = $this->articleRepository->create(['title' => 'Article 3']);
        $tag1 = $this->tagRepository->create(['name' => 'php', 'active' => 1]);
        $tag2 = $this->tagRepository->create(['name' => 'javascript', 'active' => 1]);
        $tag3 = $this->tagRepository->create(['name' => 'python', 'active' => 1]);

        $this->connection->insert('article_tag', ['article_id' => $article1->id, 'tag_id' => $tag1->id]);
        $this->connection->insert('article_tag', ['article_id' => $article2->id, 'tag_id' => $tag2->id]);
        $this->connection->insert('article_tag', ['article_id' => $article3->id, 'tag_id' => $tag3->id]);

        // Find articles with php or javascript tags
        $articles = $this->articleRepository->findBy([
            'tags.name' => ['IN', ['php', 'javascript']],
        ]);

        $this->assertCount(2, $articles);
    }

    public function testWithBelongsToManyWithSort(): void
    {
        $article = $this->articleRepository->create(['title' => 'Test Article']);
        $tag1 = $this->tagRepository->create(['name' => 'zebra', 'active' => 1]);
        $tag2 = $this->tagRepository->create(['name' => 'alpha', 'active' => 1]);

        $this->connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $tag1->id]);
        $this->connection->insert('article_tag', ['article_id' => $article->id, 'tag_id' => $tag2->id]);

        // Using ArticleWithTagsSortedRepository with sort config
        $sortedArticleRepo = new ArticleWithTagsSortedRepository($this->connection, $this->tagRepository);

        $foundArticle = $sortedArticleRepo->with(['tags'])->find($article->id);

        $this->assertNotNull($foundArticle);
        $this->assertCount(2, $foundArticle->tags);
        $this->assertEquals('alpha', $foundArticle->tags[0]->name);
        $this->assertEquals('zebra', $foundArticle->tags[1]->name);
    }
}

// Models
class ArticleModel
{
    public array $tags = [];

    public function __construct(
        public int $id,
        public string $title
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['title']
        );
    }

    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }
}

class TagModel
{
    public function __construct(
        public int $id,
        public string $name,
        public int $active = 1
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['name'],
            (int) ($data['active'] ?? 1)
        );
    }
}

// Repositories
class TagModelRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, TagModel::class, 'tags_m2m');
    }
}

class ArticleWithTagsRepository extends BaseRepository
{
    protected array $relationConfig = [];

    public TagModelRepository $tagRepository;

    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        TagModelRepository $tagRepository
    ) {
        $this->tagRepository = $tagRepository;
        $this->relationConfig = [
            'tags' => new BelongsToMany(
                repository: 'tagRepository',
                pivot: 'article_tag',
                foreignPivotKey: 'article_id',
                relatedPivotKey: 'tag_id',
                setter: 'setTags',
            ),
        ];
        parent::__construct($connection, ArticleModel::class, 'articles_m2m');
    }
}

class ArticleWithTagsSortedRepository extends BaseRepository
{
    protected array $relationConfig = [];

    public TagModelRepository $tagRepository;

    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        TagModelRepository $tagRepository
    ) {
        $this->tagRepository = $tagRepository;
        $this->relationConfig = [
            'tags' => new BelongsToMany(
                repository: 'tagRepository',
                pivot: 'article_tag',
                foreignPivotKey: 'article_id',
                relatedPivotKey: 'tag_id',
                setter: 'setTags',
                orderBy: ['name' => 'ASC'],
            ),
        ];
        parent::__construct($connection, ArticleModel::class, 'articles_m2m');
    }
}
