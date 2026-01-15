# Eager Loading

Eager loading allows you to load related entities efficiently, avoiding the N+1 query problem.

## Setup

### 1. Define Relation Config

```php
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\HasMany;

class PostRepository extends BaseRepository
{
    protected array $relationConfig = [];

    public UserRepository $userRepository;
    public CommentRepository $commentRepository;

    public function __construct(
        Connection $connection,
        UserRepository $userRepo,
        CommentRepository $commentRepo
    ) {
        $this->userRepository = $userRepo;
        $this->commentRepository = $commentRepo;

        $this->relationConfig = [
            'user' => new BelongsTo(
                repository: 'userRepository',
                foreignKey: 'user_id',
                setter: 'setUser',
            ),
            'comments' => new HasMany(
                repository: 'commentRepository',
                foreignKey: 'post_id',
                setter: 'setComments',
                orderBy: ['created_at' => 'ASC'],
            ),
        ];

        parent::__construct($connection, Post::class, 'posts');
    }
}
```

### 2. Add Setters to Model

```php
class Post
{
    public readonly int $id;
    public readonly string $title;
    public readonly int $userId;
    
    private ?User $user = null;
    private array $comments = [];

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setComments(array $comments): void
    {
        $this->comments = $comments;
    }

    public function getComments(): array
    {
        return $this->comments;
    }

    public static function fromArray(array $data): self
    {
        // ...
    }
}
```

---

## Usage

### with()

Specify relations to eager load:

```php
public function with(array $relations): static
```

**Example:**

```php
// Load single relation
$posts = $repo->with(['user'])->findAll();

// Load multiple relations
$posts = $repo->with(['user', 'comments'])->findBy(['status' => 'published']);

// Works with all find methods
$post = $repo->with(['user', 'comments'])->find(1);
$post = $repo->with(['user'])->findOneBy(['slug' => 'my-post']);
```

---

## Relation Types

### BelongsTo

N:1 relation where the current model has a foreign key.

```php
use Solo\BaseRepository\Relation\BelongsTo;

// Post belongs to User
// posts.user_id → users.id
'user' => new BelongsTo(
    repository: 'userRepository',
    foreignKey: 'user_id',      // Column in posts table
    setter: 'setUser',
),
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `repository` | `string` | Property name of the related repository |
| `foreignKey` | `string` | Foreign key column in current table |
| `setter` | `string` | Setter method name in model |
| `orderBy` | `array` | Optional ordering (rarely used for BelongsTo) |

### HasOne

1:1 relation where the related model has a foreign key.

```php
use Solo\BaseRepository\Relation\HasOne;

// User has one Profile
// profiles.user_id → users.id
'profile' => new HasOne(
    repository: 'profileRepository',
    foreignKey: 'user_id',      // Column in profiles table
    setter: 'setProfile',
),
```

**Returns:** Single object or `null`.

### HasMany

1:N relation where the related model has a foreign key.

```php
use Solo\BaseRepository\Relation\HasMany;

// Post has many Comments
// comments.post_id → posts.id
'comments' => new HasMany(
    repository: 'commentRepository',
    foreignKey: 'post_id',      // Column in comments table
    setter: 'setComments',
    orderBy: ['created_at' => 'ASC'],
),
```

**Returns:** Array of objects (empty array if none).

### BelongsToMany

N:M relation via a pivot table.

```php
use Solo\BaseRepository\Relation\BelongsToMany;

// Article has many Tags via article_tag pivot
'tags' => new BelongsToMany(
    repository: 'tagRepository',
    pivot: 'article_tag',           // Pivot table name
    foreignPivotKey: 'article_id',  // Column pointing to current model
    relatedPivotKey: 'tag_id',      // Column pointing to related model
    setter: 'setTags',
    orderBy: ['name' => 'ASC'],
),
```

**Database Structure:**

```sql
-- articles table
CREATE TABLE articles (id INT PRIMARY KEY, title VARCHAR(255));

-- tags table
CREATE TABLE tags (id INT PRIMARY KEY, name VARCHAR(255));

-- Pivot table
CREATE TABLE article_tag (
    article_id INT,
    tag_id INT,
    PRIMARY KEY (article_id, tag_id)
);
```

---

## Nested Relations

Load nested relations using dot-notation:

```php
// Load comments and each comment's author
$posts = $repo->with([
    'comments',
    'comments.user'
])->findAll();

// Access nested data
foreach ($posts as $post) {
    foreach ($post->getComments() as $comment) {
        echo $comment->getUser()->name;
    }
}
```

### Multi-Level Nesting

```php
// products → productAttributes (hasMany) → attribute (belongsTo)
$products = $productRepo->with([
    'productAttributes',
    'productAttributes.attribute'
])->findAll();
```

::: warning Performance
Deep nesting increases query count. Use judiciously.
:::

---

## How It Works

### Without Eager Loading (N+1 Problem)

```php
$posts = $repo->findAll(); // 1 query

foreach ($posts as $post) {
    echo $post->getUser()->name; // N queries!
}
// Total: N+1 queries
```

### With Eager Loading

```php
$posts = $repo->with(['user'])->findAll();
// Query 1: SELECT * FROM posts
// Query 2: SELECT * FROM users WHERE id IN (1, 2, 3, ...)

foreach ($posts as $post) {
    echo $post->getUser()->name; // No additional queries
}
// Total: 2 queries
```

---

## Practical Examples

### Blog Posts with Authors

```php
class PostRepository extends BaseRepository
{
    protected array $relationConfig = [];
    public UserRepository $userRepository;

    public function __construct(Connection $conn, UserRepository $userRepo)
    {
        $this->userRepository = $userRepo;
        $this->relationConfig = [
            'author' => new BelongsTo(
                repository: 'userRepository',
                foreignKey: 'author_id',
                setter: 'setAuthor',
            ),
        ];
        parent::__construct($conn, Post::class, 'posts');
    }

    public function findPublishedWithAuthors(): array
    {
        return $this->with(['author'])
            ->findBy(['status' => 'published'], ['created_at' => 'DESC']);
    }
}
```

### E-commerce Product with Relations

```php
class ProductRepository extends BaseRepository
{
    protected array $relationConfig = [];
    
    public CategoryRepository $categoryRepository;
    public ReviewRepository $reviewRepository;
    public TagRepository $tagRepository;

    public function __construct(/* ... */)
    {
        // Inject repositories...
        
        $this->relationConfig = [
            'category' => new BelongsTo(
                repository: 'categoryRepository',
                foreignKey: 'category_id',
                setter: 'setCategory',
            ),
            'reviews' => new HasMany(
                repository: 'reviewRepository',
                foreignKey: 'product_id',
                setter: 'setReviews',
                orderBy: ['created_at' => 'DESC'],
            ),
            'tags' => new BelongsToMany(
                repository: 'tagRepository',
                pivot: 'product_tag',
                foreignPivotKey: 'product_id',
                relatedPivotKey: 'tag_id',
                setter: 'setTags',
            ),
        ];
        
        parent::__construct($conn, Product::class, 'products');
    }

    public function findWithAllRelations(int $id): ?Product
    {
        return $this->with(['category', 'reviews', 'tags'])->find($id);
    }
}
```

---

## Combining with Soft Delete

Eager loading respects soft delete settings:

```php
class PostRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';
    protected array $relationConfig = [];
    // ...
}

// Active posts with their users
$posts = $repo->with(['user'])->findAll();

// All posts (including deleted) with users
$posts = $repo->with(['user'])->findBy(['deleted_at' => '*']);
```

::: info Related Records
If the related repository also has soft delete enabled, deleted related records will be excluded from eager loading.
:::
