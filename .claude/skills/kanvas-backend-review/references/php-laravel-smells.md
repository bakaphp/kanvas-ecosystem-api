# PHP / Laravel smells worth flagging

Concrete patterns for three of the four review categories — duplication, complexity, comments — with the reasoning behind each so you can recognise variants rather than pattern-match on these exact shapes.

Because this skill *fixes* what it finds, the last section — [things that look like smells but usually are not](#things-that-look-like-smells-but-usually-are-not) — carries as much weight as the rest. A false positive here is not a wasted line in a report; it is an unwanted edit in someone's working tree.

## Contents

- [Duplicated methods](#duplicated-methods)
- [Overly complex logic](#overly-complex-logic)
- [Unnecessary comments](#unnecessary-comments)
- [Things that look like smells but usually are not](#things-that-look-like-smells-but-usually-are-not)

The fourth review category — violations of the repo's own `CLAUDE.md` rules — has its own page: `kanvas-conventions.md`.

---

## Duplicated methods

### Copy-pasted between sibling services

The most common case. Two domain services grow the same helper because the second one was written by copying the first.

```php
// app/Services/Lead/LeadService.php
private function mapStatus(int $code): string
{
    return match ($code) {
        1 => 'open', 2 => 'won', 3 => 'lost', default => 'unknown',
    };
}

// app/Services/Deal/DealService.php — identical body
private function mapStatus(int $code): string { /* same */ }
```

Flag it as High: the next status added lands in one file only, and the bug surfaces somewhere unrelated. The fix is usually a backed enum or a shared trait.

### Resolver duplicating its service

A GraphQL mutation or query resolver that re-implements validation, tenant scoping, or transformation that the service already does. Symptom: the resolver is more than a thin call into a service plus a return.

### Reimplementing a framework or base-class affordance

Watch for hand-rolled versions of things Laravel or the codebase already provides:

- Manual `where('companies_id', ...)` scattered through queries when a global scope or trait exists.
- A private `findOrFail`-with-custom-exception helper repeated per repository.
- Manual array mapping where an existing Resource, DTO, or transformer already does it.
- Custom pagination or cursor logic next to a paginator that already handles it.

Before flagging, confirm the shared version exists — `grep` for the trait or scope name. "You should extract this" is weak advice; "this already exists at X" is strong.

### Near-duplicates that differ by a constant

Two methods differing only in a string key, a status value, or a relation name. These are duplication with extra steps: one parameterised method replaces both. Worth Medium unless the pair is already drifting.

### Multi-tenant duplication

In a multi-tenant codebase, the same tenant-scoping guard copy-pasted into every query is both duplication and a security risk — the copy someone forgets is a data leak across tenants. Flag it High and point at the scope or trait that should own it.

---

## Overly complex logic

### Nesting that hides the happy path

```php
public function handle(Order $order): void
{
    if ($order->isValid()) {
        if ($order->user->hasCredit()) {
            foreach ($order->items as $item) {
                if ($item->inStock()) {
                    // the actual work, 5 indents deep
                }
            }
        }
    }
}
```

Guard clauses invert this and the real work returns to the left margin. Rough trigger: three or more levels of nesting inside one method, or the meaningful line sitting past ~16 columns of indentation.

### One method, several jobs

A method that fetches, transforms, persists, dispatches events, and formats a response is hard to test and harder to change. The tell is the word "and" appearing when you describe what it does. Suggest the split by naming the pieces — "validation → `OrderValidator`, pricing → `CalculateOrderTotal` action" — rather than saying "this does too much".

### Conditions nobody can read

```php
if ($user->isActive() && !$user->isBanned() && ($user->subscription?->isPaid() || $user->hasTrialLeft()) && $company->allowsGuests()) {
```

Naming the parts (`$canPlaceOrder = ...`) or moving the whole thing onto the model as a named method makes the intent legible and testable. Low or Medium depending on how central the condition is.

### Growing `match` / `switch`

A `match` on a type or status that keeps gaining arms is often polymorphism or a config lookup wearing a disguise. Flag it when a new arm was added in this very diff — that is evidence of the growth, not speculation.

### Query builder sprawl

Thirty lines of conditional `->when()` / `->where()` chaining inside a controller or resolver. Usually belongs in a scope, a repository method, or a dedicated filter/builder class. Also check for N+1 patterns while you are there — a `->get()` followed by a loop that touches a relation is worth a line in the review even though it is technically a performance note, because the fix is the same refactor.

### Repeated work that should be hoisted or memoized

Cheap to fix, and the reason to look is that these compound quietly at production data volumes:

```php
private function warehouseId(): int
{
    // called 3× per run; each call is a DB hit when primary_warehouse_id is unset
    return $this->company->primary_warehouse_id ?? Warehouse::forCompany(...)->firstOrFail()->id;
}
```

Memoize into a nullable property. Same family: a collection rebuilt inside a `foreach` that could be built once before it, and a lookup map recomputed per source.

### Hydrating models to read two columns

```php
$variants = Variant::where('products_id', $id)->get();   // full Eloquent models
return $variants->mapWithKeys(fn ($v) => [$v->id => $v->name]);
```

On a six-figure catalog that is a lot of hydrated models to answer "id and name". `select(['id', 'name'])->toBase()->get()` into plain arrays does the same job. Flag it when the collection size is driven by catalog or tenant data rather than a fixed small set.

### An abstraction leaking its concrete type

A method that claims to work with any implementation but type-hints the concrete class:

```php
// "source-agnostic" comparator that only accepts one source
public function diff(KanvasWarehouseQuantitySource $source): array
```

This is worth flagging even when nothing is currently broken, because the interface is making a promise the signature does not keep — the second implementation is where it bites. The fix is usually to move whatever the concrete type was needed for out of the generic path and stamp it on afterwards.

### Dead parameters and dead variables

A parameter every call site passes the same value to, or one left behind when call sites were removed. A variable written by every caller and read by nobody. Both are safe to remove — but check the whole codebase for call sites first, and if the dead value is something a caller clearly *wanted* recorded, surfacing it properly may be the better fix than deleting it.

### Complexity that is fine

Long is not the same as complex. A 60-line method that is a flat sequence of clearly named steps reads fine. Cyclomatic branching is what hurts. Do not flag length alone.

---

## Unnecessary comments

### Restating the code

```php
// increment the counter
$counter++;

// loop through the users
foreach ($users as $user) {
```

These decay: the code changes, the comment does not, and now it lies. Low severity, but they are cheap to remove and they add up.

### Commented-out code

Dead blocks, leftover `dd()`, `// $this->oldImplementation();`. Git already remembers. Always worth flagging.

### Empty or generated docblocks

```php
/**
 * @param int $id
 * @return User
 */
public function find(int $id): User
```

The signature says all of this already. A docblock earns its place when it adds what types cannot — a thrown exception, an array's shape (`@return array<int, OrderDto>`), a unit, a nullability caveat. Flag the empty ones, keep the informative ones.

### Stale comments

A comment describing behaviour the code no longer has. These are worse than no comment because they are actively misleading. When the diff changes logic under an existing comment that was not updated, that is a High-value catch even though it is "just a comment".

### Ownerless TODOs

`// TODO: fix this properly` with no ticket, owner, or date is a note to nobody. Suggest linking a ticket or removing it.

### Stale descriptions that machines read

A `#[WorkflowAction]` description, a tool description, an MCP annotation, or a GraphQL field description that no longer matches the code. These are worse than a stale inline comment because an agent picks its next step from them — a contradictory description ("writes it and reports..." ending "it writes no stock") is worse than none at all. Always fix these, and treat them as the highest-value comment finding in an agent-facing codebase.

### Comments to defend

Do not flag these — and mention when one is missing:

```php
// Stripe returns amounts in cents; the ledger stores decimal.
$amount = $payload['amount'] / 100;

// Upstream API 500s on batches > 50, confirmed with vendor 2025-11.
$chunks = $items->chunk(50);
```

These explain *why*, which the code cannot. If the diff contains a magic number, a retry loop, a sleep, or an odd ordering with no explanation, writing that one-line why-comment yourself is a legitimate finding — but only after you have actually established the why.

The strongest version of this: code that *looks* like pointless defensiveness usually isn't. Before deleting a redundant-seeming guard or a `mixed` type-hint, check whether some path really produces the odd value.

```php
// $missing->child returns null, but a directly missing child returns an empty
// element — so the mixed guard is load-bearing if <SKU> is ever absent.
private function str(mixed $value): string
```

Finding that out and writing it down converts a near-deletion into a real improvement. Say in the report what you verified, so the developer can check your reasoning rather than take it on faith.

---

## Things that look like smells but usually are not

Flagging these erodes trust in the whole review:

- **Framework boilerplate** — resource classes, form requests, and factories look repetitive because the framework wants them that way.
- **Deliberate parallel structure** — two resolvers with the same shape but different domains are often clearer left alone than merged behind an abstraction.
- **Tests** — duplication in test setup is frequently the right call; explicit beats DRY in a test. Only flag test code when the user asked for it.
- **Interface implementations** — identical method signatures across implementors is the point of the interface.
- **Migrations and generated files** — out of scope.
- **Style the fixer already owns** — spacing, brace placement, import *order*, trailing commas, strict-type declarations. php-cs-fixer runs on every edit; re-flagging its output wastes review lines. This exemption does **not** cover the rules in `kanvas-conventions.md` — the rule of 4, named arguments, and inline FQCNs look like style but no tool checks them, which is exactly why they survive into review.
