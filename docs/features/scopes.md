# Scopes

Scopes map a **virtual criteria key** to a reusable criteria fragment. The fragment is defined once in the repository; callers activate it by passing the key like any other criteria entry.

```php
// 'adult' is not a column — the repository expands it
$users = $repo->findBy(['status' => 'active', 'adult' => true]);
```

## Defining Scopes

Override `scopes()` in your repository. Each handler receives the key's value and returns a criteria fragment — or `null` to add no condition:

```php
class UserRepository extends BaseRepository
{
    protected function scopes(): array
    {
        return [
            // Boolean toggle
            'adult' => fn($value) => filter_var($value, FILTER_VALIDATE_BOOL)
                ? ['age' => ['>=' => 18]]
                : null,

            // Parameterized: the value feeds the fragment
            'min_age' => fn($value) => $value === null
                ? null
                : ['age' => ['>=' => (int) $value]],

            // Any criteria syntax works, including OR groups and relation keys
            'verified' => fn($value) => filter_var($value, FILTER_VALIDATE_BOOL)
                ? ['OR' => [
                      'verified_at' => ['!=' => null],
                      'documents.approved' => 1,
                  ]]
                : null,
        ];
    }
}
```

The fragment passes through the regular criteria compiler, so the full [criteria syntax](/features/criteria) is available: operators, `OR`/`AND` groups, and relation dot-notation (compiled to `EXISTS`).

### Naming Rules

Scope definitions are validated once, on the repository's first query. A violation throws `InvalidArgumentException`:

- names must be non-empty strings and plain identifiers — letters, digits, underscores; no dots, no `!`;
- names must not collide with reserved criteria keywords (`OR`, `AND`, `LIKE`, `IN`, `BETWEEN` — case-insensitive), the **primary key column**, or the **soft-delete column**: the library generates criteria with those keys internally, and a scope would hijack them;
- never name a scope after a real column — the scope shadows the column at the top level of every criteria array.

### Handler Contract

- Handlers must accept `mixed` (untyped or `mixed $value`). The dispatch happens inside the library, which runs under `strict_types`, so a narrower parameter type like `fn(int $v)` throws `TypeError` on HTTP string input.
- Handlers must return `array` or `null`. Anything else throws an `InvalidArgumentException` naming the scope.
- Keep handlers pure: build and return a fragment. Running queries inside a handler is unsupported.

## How It Works

1. Before compiling SQL, scope keys are looked up at the **top level** of the criteria array.
2. A matched key is removed and its handler is called with the key's value.
3. A non-empty fragment is appended as a nested group, AND-joined with the remaining criteria.
4. `null` or `[]` adds no condition — the key is still consumed, so it never reaches SQL.

Scope expansion runs **before** soft-delete processing, so the implicit `deleted_at IS NULL` filter is applied after — and unaffected by — your scopes.

Scopes apply to every criteria-accepting method: `findBy()`, `findOneBy()`, `count()`, `exists()`, aggregates, `updateBy()`, `deleteBy()` and `forceDeleteBy()`. (`delete($id)` and `restore($id)` build their criteria from the primary key internally; `forceDelete($id)` deletes by id without criteria at all.)

```php
$repo->count(['adult' => true]);
$repo->updateBy(['adult' => true], ['plan' => 'full']);
```

::: tip Scope keys are top-level only
A scope key nested inside an `OR`/`AND` group or a list-form entry is compiled as a regular **column** — likely an unknown-column SQL error, or a silently wrong filter if a real column shares the name. For the same reason scopes cannot reference other scopes from their fragments. Keep scope activation at the top level of the criteria array.
:::

### Write Safety

On write paths (`updateBy`, `deleteBy`, `forceDeleteBy`), criteria that scope expansion collapsed to nothing are **refused** with an `InvalidArgumentException` — a filter that evaluates to "no condition" must not silently become an unbounded write. Passing `[]` explicitly keeps its usual full-table meaning.

```php
$repo->deleteBy(['adult' => '0']);   // throws: expansion left no conditions
$repo->updateBy([], ['flag' => 0]);  // allowed: explicit full-table update
```

### Soft Delete Sentinel

Scope fragments cannot control soft-delete visibility: the special `'*'` value is recognized only as a **top-level** criteria entry supplied by the caller.

```php
// Works: '*' stays top-level, the scope expands independently
$repo->findBy(['adult' => true, 'deleted_at' => '*']);

// Does not work: '*' inside a fragment compiles as a literal comparison
'with_trashed' => fn($v) => ['deleted_at' => '*'],
```

## HTTP Filters

Scopes are designed for externally supplied filters: a flat query-string key activates a predefined condition without a translation layer in between.

```php
// ?adult=1&min_age=21
$criteria = array_intersect_key($request->getQueryParams(), array_flip([
    'status', 'adult', 'min_age',
]));

$users = $repo->findBy($criteria);
```

Handlers receive the raw value (`'1'`, `'true'`, `'0'`, an array for `?key[]=` input, …) — normalize it inside the scope, e.g. with `filter_var($value, FILTER_VALIDATE_BOOL)`.

::: warning User input
Scopes do not replace an allow-list. Any unknown key in the criteria array is still treated as a column filter — always restrict externally supplied keys as shown above.
:::

## Filter-Only Relations

Scope fragments may use relation dot-notation, which requires a configured relation. A relation can be configured without a `setter` purely for filtering — see [Filter-Only Relations](/features/eager-loading#filter-only-relations). Requesting such a relation via `with()` throws immediately.

## See Also

For reusable query logic exposed as repository **methods** (rather than criteria keys), see [Extending Repositories](/advanced/extending#scoped-queries).
