---
layout: home

hero:
  name: Solo Base Repository
  text: Lightweight PHP Repository Pattern
  tagline: Built-in soft delete, eager loading, and rich criteria syntax. Powered by Doctrine DBAL.
  image:
    src: /logo.svg
    alt: Solo Base Repository
  actions:
    - theme: brand
      text: Get Started
      link: /guide/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/solophp/base-repository

features:
  - icon: 🗑️
    title: Soft Delete
    details: Configurable soft delete with deleted_at column. Safe by default — only active records returned.
  - icon: 🔗
    title: Eager Loading
    details: Load related entities efficiently with with(). Supports nested relations via dot-notation.
  - icon: 🔍
    title: Rich Criteria Syntax
    details: Expressive filtering with equality, NULL checks, IN lists, operators, and relation EXISTS subqueries.
  - icon: 📊
    title: Aggregations
    details: Built-in count(), sum(), avg(), min(), max() with full criteria support.
  - icon: 🔐
    title: Transactions
    details: withTransaction() helper for clean transactional code with automatic commit/rollback.
  - icon: 🆔
    title: Custom IDs
    details: Auto-detect custom IDs (UUIDs, prefixed IDs). Just pass your ID in create() — no configuration needed.
---

<style>
:root {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #06b6d4 0%, #8b5cf6 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #06b6d430 0%, #8b5cf630 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

## Quick Example

```php
use Solo\BaseRepository\BaseRepository;
use Solo\BaseRepository\Relation\BelongsTo;
use Solo\BaseRepository\Relation\HasMany;

class PostRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';
    protected array $relationConfig = [];

    public function __construct(
        Connection $connection,
        public UserRepository $userRepository,
        public CommentRepository $commentRepository
    ) {
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
            ),
        ];

        parent::__construct($connection, Post::class, 'posts');
    }
}

// Usage
$posts = $postRepository
    ->with(['user', 'comments'])
    ->findBy(['status' => 'published'], ['created_at' => 'DESC'], 20, 1);
```

## Installation

```bash
composer require solophp/base-repository
```

**Requirements:** PHP 8.3+, Doctrine DBAL ^4.3
