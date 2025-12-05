<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\HasMany;

class RelationCriteriaTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private ArticleRepository $articleRepository;
    private TagRepository $tagRepository;
    private CategoryRepository $categoryRepository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL,
                category_id INTEGER NOT NULL,
                status VARCHAR(50) DEFAULT "draft"
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                article_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                active INTEGER DEFAULT 1
            )
        ');

        $this->categoryRepository = new CategoryRepository($this->connection);
        $this->tagRepository = new TagRepository($this->connection);
        $this->articleRepository = new ArticleRepository(
            $this->connection,
            $this->categoryRepository,
            $this->tagRepository
        );
    }

    public function testFindByWithHasManyRelationCriteria(): void
    {
        $category = $this->categoryRepository->create(['name' => 'Tech']);
        $article1 = $this->articleRepository->create(['title' => 'Article 1', 'category_id' => $category->id]);
        $article2 = $this->articleRepository->create(['title' => 'Article 2', 'category_id' => $category->id]);

        $this->tagRepository->create(['article_id' => $article1->id, 'name' => 'php', 'active' => 1]);
        $this->tagRepository->create(['article_id' => $article2->id, 'name' => 'javascript', 'active' => 1]);

        // Find articles that have tag 'php'
        $articles = $this->articleRepository->findBy(['tags.name' => 'php']);

        $this->assertCount(1, $articles);
        $this->assertEquals('Article 1', $articles[0]->title);
    }

    public function testFindByWithBelongsToRelationCriteria(): void
    {
        $techCategory = $this->categoryRepository->create(['name' => 'Tech']);
        $newsCategory = $this->categoryRepository->create(['name' => 'News']);

        $this->articleRepository->create(['title' => 'Tech Article', 'category_id' => $techCategory->id]);
        $this->articleRepository->create(['title' => 'News Article', 'category_id' => $newsCategory->id]);

        // Find articles in Tech category
        $articles = $this->articleRepository->findBy(['category.name' => 'Tech']);

        $this->assertCount(1, $articles);
        $this->assertEquals('Tech Article', $articles[0]->title);
    }

    public function testFindByWithNotExistsRelationCriteria(): void
    {
        $category = $this->categoryRepository->create(['name' => 'Tech']);
        $article1 = $this->articleRepository->create(['title' => 'With Tags', 'category_id' => $category->id]);
        $article2 = $this->articleRepository->create(['title' => 'Without Tags', 'category_id' => $category->id]);

        $this->tagRepository->create(['article_id' => $article1->id, 'name' => 'php', 'active' => 1]);

        // Find articles that DON'T have active tags
        $articles = $this->articleRepository->findBy(['!tags.active' => 1]);

        $this->assertCount(1, $articles);
        $this->assertEquals('Without Tags', $articles[0]->title);
    }

    public function testFindByWithMultipleRelationCriteria(): void
    {
        $techCategory = $this->categoryRepository->create(['name' => 'Tech']);
        $newsCategory = $this->categoryRepository->create(['name' => 'News']);

        $article1 = $this->articleRepository->create(['title' => 'Tech PHP', 'category_id' => $techCategory->id]);
        $article2 = $this->articleRepository->create(['title' => 'Tech JS', 'category_id' => $techCategory->id]);
        $article3 = $this->articleRepository->create(['title' => 'News PHP', 'category_id' => $newsCategory->id]);

        $this->tagRepository->create(['article_id' => $article1->id, 'name' => 'php']);
        $this->tagRepository->create(['article_id' => $article2->id, 'name' => 'javascript']);
        $this->tagRepository->create(['article_id' => $article3->id, 'name' => 'php']);

        // Find articles in Tech category with php tag
        $articles = $this->articleRepository->findBy([
            'category.name' => 'Tech',
            'tags.name' => 'php',
        ]);

        $this->assertCount(1, $articles);
        $this->assertEquals('Tech PHP', $articles[0]->title);
    }

    public function testFindByWithRelationCriteriaAndBaseCriteria(): void
    {
        $category = $this->categoryRepository->create(['name' => 'Tech']);

        $article1 = $this->articleRepository->create([
            'title' => 'Published Article',
            'category_id' => $category->id,
            'status' => 'published',
        ]);
        $article2 = $this->articleRepository->create([
            'title' => 'Draft Article',
            'category_id' => $category->id,
            'status' => 'draft',
        ]);

        $this->tagRepository->create(['article_id' => $article1->id, 'name' => 'php']);
        $this->tagRepository->create(['article_id' => $article2->id, 'name' => 'php']);

        // Find published articles with php tag
        $articles = $this->articleRepository->findBy([
            'status' => 'published',
            'tags.name' => 'php',
        ]);

        $this->assertCount(1, $articles);
        $this->assertEquals('Published Article', $articles[0]->title);
    }

    public function testFindByWithRelationCriteriaInList(): void
    {
        $category = $this->categoryRepository->create(['name' => 'Tech']);

        $article1 = $this->articleRepository->create(['title' => 'Article 1', 'category_id' => $category->id]);
        $article2 = $this->articleRepository->create(['title' => 'Article 2', 'category_id' => $category->id]);
        $article3 = $this->articleRepository->create(['title' => 'Article 3', 'category_id' => $category->id]);

        $this->tagRepository->create(['article_id' => $article1->id, 'name' => 'php']);
        $this->tagRepository->create(['article_id' => $article2->id, 'name' => 'javascript']);
        $this->tagRepository->create(['article_id' => $article3->id, 'name' => 'python']);

        // Find articles with php or javascript tags
        $articles = $this->articleRepository->findBy([
            'tags.name' => ['IN', ['php', 'javascript']],
        ]);

        $this->assertCount(2, $articles);
    }

    public function testFindByWithRelationCriteriaOperator(): void
    {
        $category = $this->categoryRepository->create(['name' => 'Tech']);

        $article1 = $this->articleRepository->create(['title' => 'Article 1', 'category_id' => $category->id]);
        $article2 = $this->articleRepository->create(['title' => 'Article 2', 'category_id' => $category->id]);

        $this->tagRepository->create(['article_id' => $article1->id, 'name' => 'php', 'active' => 1]);
        $this->tagRepository->create(['article_id' => $article2->id, 'name' => 'javascript', 'active' => 0]);

        // Find articles with active tags
        $articles = $this->articleRepository->findBy([
            'tags.active' => ['=', 1],
        ]);

        $this->assertCount(1, $articles);
        $this->assertEquals('Article 1', $articles[0]->title);
    }

    public function testCountWithRelationCriteria(): void
    {
        $category = $this->categoryRepository->create(['name' => 'Tech']);

        $article1 = $this->articleRepository->create(['title' => 'Article 1', 'category_id' => $category->id]);
        $article2 = $this->articleRepository->create(['title' => 'Article 2', 'category_id' => $category->id]);

        $this->tagRepository->create(['article_id' => $article1->id, 'name' => 'php']);

        $count = $this->articleRepository->count(['tags.name' => 'php']);

        $this->assertEquals(1, $count);
    }

    public function testExistsWithRelationCriteria(): void
    {
        $category = $this->categoryRepository->create(['name' => 'Tech']);
        $article = $this->articleRepository->create(['title' => 'Article', 'category_id' => $category->id]);

        $this->tagRepository->create(['article_id' => $article->id, 'name' => 'php']);

        $this->assertTrue($this->articleRepository->exists(['tags.name' => 'php']));
        $this->assertFalse($this->articleRepository->exists(['tags.name' => 'ruby']));
    }
}

// Models
class Category
{
    public function __construct(
        public int $id,
        public string $name
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], $data['name']);
    }
}

class Article
{
    public ?Category $category = null;
    public array $tags = [];

    public function __construct(
        public int $id,
        public string $title,
        public int $category_id,
        public string $status = 'draft'
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['title'],
            (int) $data['category_id'],
            $data['status'] ?? 'draft'
        );
    }

    public function setCategory(?Category $category): void
    {
        $this->category = $category;
    }

    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }
}

class Tag
{
    public function __construct(
        public int $id,
        public int $article_id,
        public string $name,
        public int $active = 1
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (int) $data['article_id'],
            $data['name'],
            (int) ($data['active'] ?? 1)
        );
    }
}

// Repositories
class CategoryRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, Category::class, 'categories');
    }
}

class TagRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, Tag::class, 'tags');
    }
}

class ArticleRepository extends BaseRepository
{
    protected array $relationConfig = [];

    public CategoryRepository $categoryRepository;
    public TagRepository $tagRepository;

    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        CategoryRepository $categoryRepository,
        TagRepository $tagRepository
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->tagRepository = $tagRepository;
        $this->relationConfig = [
            'category' => new BelongsTo(
                repository: 'categoryRepository',
                foreignKey: 'category_id',
                setter: 'setCategory',
            ),
            'tags' => new HasMany(
                repository: 'tagRepository',
                foreignKey: 'article_id',
                setter: 'setTags',
            ),
        ];
        parent::__construct($connection, Article::class, 'articles');
    }
}
