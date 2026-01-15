# Extending Repositories

Add domain-specific methods to your repositories for cleaner application code.

## Protected Methods

These methods are available for use in your repository subclasses:

| Method | Description |
|--------|-------------|
| `table()` | Returns QueryBuilder with table selected |
| `queryBuilder()` | Returns fresh QueryBuilder |
| `mapRowToModel(array $row)` | Converts database row to model |
| `applyCriteria(QueryBuilder $qb, array $criteria)` | Applies criteria to query |
| `applyOrderBy(QueryBuilder $qb, array $orderBy)` | Applies sorting |

---

## Basic Extension

```php
final class UserRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';

    public function __construct(Connection $connection)
    {
        parent::__construct($connection, User::class, 'users');
    }

    /**
     * Find users by email domain
     */
    public function findByEmailDomain(string $domain): array
    {
        return $this->findBy([
            'email' => ['LIKE' => '%@' . $domain]
        ]);
    }

    /**
     * Find active users with minimum balance
     */
    public function findActiveWithMinBalance(float $minBalance): array
    {
        return $this->findBy([
            'status' => 'active',
            'balance' => ['>=' => $minBalance]
        ]);
    }
}
```

---

## Using QueryBuilder

For complex queries, use `table()` which returns a QueryBuilder:

```php
final class UserRepository extends BaseRepository
{
    /**
     * Find top users by score
     */
    public function findTopByScore(int $limit = 10): array
    {
        $rows = $this->table()
            ->andWhere('status = :status')
            ->setParameter('status', 'active')
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            fn(array $row) => $this->mapRowToModel($row),
            $rows
        );
    }

    /**
     * Find users registered in date range
     */
    public function findRegisteredBetween(
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        $rows = $this->table()
            ->andWhere('created_at >= :from')
            ->andWhere('created_at <= :to')
            ->setParameter('from', $from->format('Y-m-d H:i:s'))
            ->setParameter('to', $to->format('Y-m-d H:i:s'))
            ->orderBy('created_at', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            fn(array $row) => $this->mapRowToModel($row),
            $rows
        );
    }
}
```

---

## Complex Queries with Joins

```php
final class OrderRepository extends BaseRepository
{
    /**
     * Find orders with user info (manual join)
     */
    public function findOrdersWithUserEmail(string $email): array
    {
        $rows = $this->queryBuilder()
            ->select('o.*')
            ->from('orders', 'o')
            ->innerJoin('o', 'users', 'u', 'o.user_id = u.id')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->orderBy('o.created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            fn(array $row) => $this->mapRowToModel($row),
            $rows
        );
    }

    /**
     * Get order statistics by status
     */
    public function getStatsByStatus(): array
    {
        return $this->queryBuilder()
            ->select('status', 'COUNT(*) as count', 'SUM(total) as total')
            ->from('orders')
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
```

---

## Scoped Queries

Create reusable query scopes:

```php
final class ProductRepository extends BaseRepository
{
    /**
     * Only active products
     */
    public function active(): array
    {
        return $this->findBy(['status' => 'active']);
    }

    /**
     * Only in-stock products
     */
    public function inStock(): array
    {
        return $this->findBy([
            'status' => 'active',
            'quantity' => ['>' => 0]
        ]);
    }

    /**
     * Products in price range
     */
    public function inPriceRange(float $min, float $max): array
    {
        return $this->findBy([
            'price' => ['>=' => $min],
            'price' => ['<=' => $max],  // Note: this overwrites!
        ]);
    }

    /**
     * Better: complex price range query
     */
    public function findInPriceRange(float $min, float $max): array
    {
        $rows = $this->table()
            ->andWhere('price >= :min')
            ->andWhere('price <= :max')
            ->setParameter('min', $min)
            ->setParameter('max', $max)
            ->orderBy('price', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            fn(array $row) => $this->mapRowToModel($row),
            $rows
        );
    }
}
```

---

## Business Logic Methods

```php
final class OrderRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';

    /**
     * Create order with items
     */
    public function createWithItems(
        int $userId,
        array $items,
        OrderItemRepository $itemRepo
    ): Order {
        return $this->withTransaction(function () use ($userId, $items, $itemRepo) {
            // Create order
            $order = $this->create([
                'user_id' => $userId,
                'status' => 'pending',
                'total' => 0
            ]);

            // Create items and calculate total
            $total = 0;
            foreach ($items as $item) {
                $itemRepo->create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price']
                ]);
                $total += $item['quantity'] * $item['price'];
            }

            // Update total
            return $this->update($order->id, ['total' => $total]);
        });
    }

    /**
     * Cancel order with reason
     */
    public function cancel(int $orderId, string $reason): Order
    {
        $order = $this->find($orderId);
        
        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        if ($order->status === 'shipped') {
            throw new \RuntimeException('Cannot cancel shipped order');
        }

        return $this->update($orderId, [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancel_reason' => $reason
        ]);
    }
}
```

---

## Full Example

```php
final class ArticleRepository extends BaseRepository
{
    protected ?string $deletedAtColumn = 'deleted_at';
    protected array $relationConfig = [];

    public UserRepository $userRepository;
    public TagRepository $tagRepository;

    public function __construct(
        Connection $connection,
        UserRepository $userRepository,
        TagRepository $tagRepository
    ) {
        $this->userRepository = $userRepository;
        $this->tagRepository = $tagRepository;

        $this->relationConfig = [
            'author' => new BelongsTo(
                repository: 'userRepository',
                foreignKey: 'author_id',
                setter: 'setAuthor',
            ),
            'tags' => new BelongsToMany(
                repository: 'tagRepository',
                pivot: 'article_tag',
                foreignPivotKey: 'article_id',
                relatedPivotKey: 'tag_id',
                setter: 'setTags',
            ),
        ];

        parent::__construct($connection, Article::class, 'articles');
    }

    // === Query Methods ===

    public function findPublished(): array
    {
        return $this->with(['author', 'tags'])
            ->findBy(
                ['status' => 'published'],
                ['published_at' => 'DESC']
            );
    }

    public function findBySlug(string $slug): ?Article
    {
        return $this->with(['author', 'tags'])
            ->findOneBy(['slug' => $slug]);
    }

    public function findByTag(string $tagSlug): array
    {
        return $this->findBy(['tags.slug' => $tagSlug]);
    }

    public function findPopular(int $limit = 10): array
    {
        $rows = $this->table()
            ->andWhere('status = :status')
            ->setParameter('status', 'published')
            ->orderBy('views', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            fn(array $row) => $this->mapRowToModel($row),
            $rows
        );
    }

    // === Business Logic ===

    public function publish(int $id): Article
    {
        return $this->update($id, [
            'status' => 'published',
            'published_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function incrementViews(int $id): void
    {
        $this->queryBuilder()
            ->update('articles')
            ->set('views', 'views + 1')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    // === Statistics ===

    public function getMonthlyStats(int $year, int $month): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        return [
            'published' => $this->count([
                'status' => 'published',
                'published_at' => ['>=' => $startDate],
                'published_at' => ['<=' => $endDate . ' 23:59:59'],
            ]),
            'total_views' => $this->sum('views', [
                'published_at' => ['>=' => $startDate],
            ]),
        ];
    }
}
```

---

## Tips

::: tip Use `mapRowToModel()`
Always use `mapRowToModel()` to convert database rows to models. This ensures consistent object creation.
:::

::: tip Keep Repositories Focused
Repositories should handle data access, not business logic. Consider service classes for complex operations.
:::

::: warning Avoid Exposing QueryBuilder
Don't return QueryBuilder from public methods. Always return models or arrays.
:::
