<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\HasMany;
use Solo\BaseRepository\Relation\HasOne;

class EagerLoadingTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private PostRepository $postRepository;
    private CommentRepository $commentRepository;
    private AuthorRepository $authorRepository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('
            CREATE TABLE authors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL,
                author_id INTEGER NOT NULL
            )
        ');

        $this->connection->executeStatement('
            CREATE TABLE comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                content TEXT NOT NULL
            )
        ');

        $this->authorRepository = new AuthorRepository($this->connection);
        $this->commentRepository = new CommentRepository($this->connection);
        $this->postRepository = new PostRepository(
            $this->connection,
            $this->authorRepository,
            $this->commentRepository
        );

        // Create profile table for hasOne test
        $this->connection->executeStatement('
            CREATE TABLE profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                author_id INTEGER NOT NULL,
                bio TEXT NOT NULL
            )
        ');
    }

    public function testWithBelongsTo(): void
    {
        $author = $this->authorRepository->create(['name' => 'John Doe']);
        $post = $this->postRepository->create(['title' => 'Test Post', 'author_id' => $author->id]);

        $foundPost = $this->postRepository->with(['author'])->find($post->id);

        $this->assertNotNull($foundPost);
        $this->assertNotNull($foundPost->author);
        $this->assertEquals('John Doe', $foundPost->author->name);
    }

    public function testWithHasMany(): void
    {
        $author = $this->authorRepository->create(['name' => 'John Doe']);
        $post = $this->postRepository->create(['title' => 'Test Post', 'author_id' => $author->id]);
        $this->commentRepository->create(['post_id' => $post->id, 'content' => 'Comment 1']);
        $this->commentRepository->create(['post_id' => $post->id, 'content' => 'Comment 2']);

        $foundPost = $this->postRepository->with(['comments'])->find($post->id);

        $this->assertNotNull($foundPost);
        $this->assertCount(2, $foundPost->comments);
        $this->assertEquals('Comment 1', $foundPost->comments[0]->content);
    }

    public function testWithMultipleRelations(): void
    {
        $author = $this->authorRepository->create(['name' => 'John Doe']);
        $post = $this->postRepository->create(['title' => 'Test Post', 'author_id' => $author->id]);
        $this->commentRepository->create(['post_id' => $post->id, 'content' => 'Comment 1']);

        $foundPost = $this->postRepository->with(['author', 'comments'])->find($post->id);

        $this->assertNotNull($foundPost);
        $this->assertNotNull($foundPost->author);
        $this->assertCount(1, $foundPost->comments);
    }

    public function testWithOnFindBy(): void
    {
        $author = $this->authorRepository->create(['name' => 'John Doe']);
        $this->postRepository->create(['title' => 'Post 1', 'author_id' => $author->id]);
        $this->postRepository->create(['title' => 'Post 2', 'author_id' => $author->id]);

        $posts = $this->postRepository->with(['author'])->findBy([]);

        $this->assertCount(2, $posts);
        foreach ($posts as $post) {
            $this->assertNotNull($post->author);
            $this->assertEquals('John Doe', $post->author->name);
        }

        // Also test findAll (same as findBy with empty criteria)
        $allPosts = $this->postRepository->with(['author'])->findAll();
        $this->assertCount(2, $allPosts);
        $this->assertNotNull($allPosts[0]->author);
    }

    public function testWithOnFindOneBy(): void
    {
        $author = $this->authorRepository->create(['name' => 'John Doe']);
        $post = $this->postRepository->create(['title' => 'Unique Title', 'author_id' => $author->id]);

        $foundPost = $this->postRepository->with(['author'])->findOneBy(['title' => 'Unique Title']);

        $this->assertNotNull($foundPost);
        $this->assertNotNull($foundPost->author);
    }

    public function testWithReturnsEmptyArrayForHasManyWhenNoRelated(): void
    {
        $author = $this->authorRepository->create(['name' => 'John Doe']);
        $post = $this->postRepository->create(['title' => 'No Comments Post', 'author_id' => $author->id]);

        $foundPost = $this->postRepository->with(['comments'])->find($post->id);

        $this->assertNotNull($foundPost);
        $this->assertIsArray($foundPost->comments);
        $this->assertEmpty($foundPost->comments);
    }

    public function testWithoutEagerLoadingDoesNotLoadRelations(): void
    {
        $author = $this->authorRepository->create(['name' => 'John Doe']);
        $post = $this->postRepository->create(['title' => 'Test Post', 'author_id' => $author->id]);

        $foundPost = $this->postRepository->find($post->id);

        $this->assertNotNull($foundPost);
        $this->assertNull($foundPost->author);
        $this->assertEmpty($foundPost->comments);
    }

    public function testWithOnCreate(): void
    {
        $author = $this->authorRepository->create(['name' => 'John Doe']);

        $post = $this->postRepository->with(['author'])->create(['title' => 'Test Post', 'author_id' => $author->id]);

        $this->assertNotNull($post->author);
        $this->assertEquals('John Doe', $post->author->name);
    }

    public function testWithOnUpdate(): void
    {
        $author = $this->authorRepository->create(['name' => 'John Doe']);
        $post = $this->postRepository->create(['title' => 'Test Post', 'author_id' => $author->id]);

        $updatedPost = $this->postRepository->with(['author'])->update($post->id, ['title' => 'Updated Title']);

        $this->assertNotNull($updatedPost->author);
        $this->assertEquals('John Doe', $updatedPost->author->name);
        $this->assertEquals('Updated Title', $updatedPost->title);
    }

    public function testWithHasOne(): void
    {
        $profileRepository = new ProfileRepository($this->connection);
        $authorWithProfileRepository = new AuthorWithProfileRepository(
            $this->connection,
            $profileRepository
        );

        $author = $authorWithProfileRepository->create(['name' => 'John Doe']);
        $profileRepository->create(['author_id' => $author->id, 'bio' => 'Test bio']);

        $foundAuthor = $authorWithProfileRepository->with(['profile'])->find($author->id);

        $this->assertNotNull($foundAuthor);
        $this->assertNotNull($foundAuthor->profile);
        $this->assertEquals('Test bio', $foundAuthor->profile->bio);
    }

    public function testWithHasOneWhenNoRelated(): void
    {
        $profileRepository = new ProfileRepository($this->connection);
        $authorWithProfileRepository = new AuthorWithProfileRepository(
            $this->connection,
            $profileRepository
        );

        $author = $authorWithProfileRepository->create(['name' => 'John Doe']);

        $foundAuthor = $authorWithProfileRepository->with(['profile'])->find($author->id);

        $this->assertNotNull($foundAuthor);
        $this->assertNull($foundAuthor->profile);
    }

    public function testWithEmptyResults(): void
    {
        // Test hasMany branch when no items found
        $posts = $this->postRepository->with(['comments'])->findBy(['id' => 999]);
        $this->assertEmpty($posts);

        // Test loadEagerRelations when items are empty
        $result = $this->postRepository->loadEagerRelations([], ['author', 'comments']);
        $this->assertEmpty($result);
    }
}

// Models
class Author
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

class Post
{
    public ?Author $author = null;
    public array $comments = [];

    public function __construct(
        public int $id,
        public string $title,
        public int $author_id
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['title'],
            (int) $data['author_id']
        );
    }

    public function setAuthor(?Author $author): void
    {
        $this->author = $author;
    }

    public function setComments(array $comments): void
    {
        $this->comments = $comments;
    }
}

class Comment
{
    public function __construct(
        public int $id,
        public int $post_id,
        public string $content
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (int) $data['post_id'],
            $data['content']
        );
    }
}

// Repositories
class AuthorRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, Author::class, 'authors');
    }
}

class CommentRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, Comment::class, 'comments');
    }
}

class PostRepository extends BaseRepository
{
    protected array $relationConfig = [];

    public AuthorRepository $authorRepository;
    public CommentRepository $commentRepository;

    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        AuthorRepository $authorRepository,
        CommentRepository $commentRepository
    ) {
        $this->authorRepository = $authorRepository;
        $this->commentRepository = $commentRepository;
        $this->relationConfig = [
            'author' => new BelongsTo(
                repository: 'authorRepository',
                foreignKey: 'author_id',
                setter: 'setAuthor',
            ),
            'comments' => new HasMany(
                repository: 'commentRepository',
                foreignKey: 'post_id',
                setter: 'setComments',
            ),
        ];
        parent::__construct($connection, Post::class, 'posts');
    }
}

// Profile model for hasOne test
class Profile
{
    public function __construct(
        public int $id,
        public int $author_id,
        public string $bio
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (int) $data['author_id'],
            $data['bio']
        );
    }
}

class ProfileRepository extends BaseRepository
{
    public function __construct(\Doctrine\DBAL\Connection $connection)
    {
        parent::__construct($connection, Profile::class, 'profiles');
    }
}

// Author with profile for hasOne test
class AuthorWithProfile
{
    public ?Profile $profile = null;

    public function __construct(
        public int $id,
        public string $name
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], $data['name']);
    }

    public function setProfile(?Profile $profile): void
    {
        $this->profile = $profile;
    }
}

class AuthorWithProfileRepository extends BaseRepository
{
    protected array $relationConfig = [];

    public ProfileRepository $profileRepository;

    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        ProfileRepository $profileRepository
    ) {
        $this->profileRepository = $profileRepository;
        $this->relationConfig = [
            'profile' => new HasOne(
                repository: 'profileRepository',
                foreignKey: 'author_id',
                setter: 'setProfile',
            ),
        ];
        parent::__construct($connection, AuthorWithProfile::class, 'authors');
    }
}
