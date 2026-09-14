# Kanvas conventions the review must enforce

php-cs-fixer and PHPStan catch spacing, import order, and types. They do **not** catch anything on this page. These are the house rules from the repo's `CLAUDE.md` files, and a violation that ships is a violation the next reviewer has to catch by hand — so they belong in this pass.

`CLAUDE.md` is the source of truth, not this file. This page is a working checklist of the rules that are (a) mechanical enough to fix in a cleanup pass and (b) routinely missed. When the two disagree, `CLAUDE.md` wins — and update this page.

## Load the rules for the trees you touched

Root conventions always apply. Sub-directory `CLAUDE.md` files load additively for the trees the diff actually touches, and new ones appear over time — discover them rather than working from a hardcoded list:

```bash
cat .claude/CLAUDE.md
find . -name CLAUDE.md -not -path './vendor/*' -not -path './node_modules/*'
```

Then read the ones whose directory contains a file in your diff. Editing `src/Domains/Connectors/Yusen/**` means `src/Domains/Connectors/CLAUDE.md` is in force; touching `tests/**` pulls in `tests/CLAUDE.md`; a `graphql/schemas/**` change pulls in that tree's file. Reading only the root file and calling the pass done is the common failure.

Skills carry rules too. If the diff scaffolds a CRUD, a connector, or an `@search` query, the matching skill under `.claude/skills/` is the spec that change should have followed.

---

## Formatting rules cs-fixer does not enforce

### The rule of 4

**4 or more arguments or parameters → one per line, vertically. 3 or fewer stays inline.** Applies to calls and signatures alike, and to our own methods only — native functions (`str_replace`, `preg_replace`, `array_merge`, …) stay inline no matter how many arguments they take.

```php
// 3 or fewer — inline is correct, do not expand it
$this->fetchPeopleCandidates($app, $company, $terms);

// 4+ call — must be vertical
$this->uploadImageToEntity(
    $company,
    app(Apps::class),
    auth()->user(),
    $request['file'],
    'photo'
);

// 4+ signature — same rule
protected function assembleBulkResults(
    array $terms,
    array $candidates,
    int $maxMatches,
    callable $present
): array {
```

This is the single most-violated rule in the codebase because the fixer is silent on it and a 4-argument call reads fine at 90 columns. Check **every** call and signature in the diff, including constructor calls (`new Foo(a, b, c, d)`), `dispatch(new Job(...))` payloads, DTO construction, and test arrange blocks.

Fix directly — it is pure formatting, zero blast radius. Do not reformat 4+ calls in code the diff did not touch; that buries the real findings.

The reverse violation counts too: a 3-argument call artificially exploded across four lines should come back inline.

### Named arguments instead of positional `null`s

When a call passes `null` for an optional middle parameter only to reach a later one, switch the whole call to named arguments and drop the `null`.

```php
// WRONG — positional null just to reach $user
$handler->setConfiguration(
    $this->agent,
    $this->message->entity()->people,
    null,
    $this->message->company->getAiAgentUserOrFail(),
);

// CORRECT
$handler->setConfiguration(
    agent: $this->agent,
    entity: $this->message->entity()->people,
    user: $this->message->company->getAiAgentUserOrFail(),
);
```

Why it is worth an edit and not just a note: renaming a parameter or inserting a new optional one in between silently rebinds every positional caller. Fix directly.

Related, same family: an LLM-tool parameter declared `type $param = 'default'` should be `?type $param = null` with `$param ?? 'default'` in the body — otherwise the model passing an explicit `null` is a `TypeError`.

### No inline fully-qualified class names

`use` imports at the top, short name everywhere — **including docblock `@property`/`@param`/`@return` annotations and `catch` blocks**, which is where they hide.

```php
} catch (\Throwable $e) {          // WRONG
/** @property \Illuminate\Support\Carbon|null $approved_at */   // WRONG
```

cs-fixer does not add the import for you. Fix directly: add the `use`, replace every occurrence, keep the use block alphabetical across the whole block (not per namespace group).

### PHP 8.4 instantiation

`new CreateActionAction(...)->execute()`, never `(new CreateActionAction(...))->execute()`. Fix directly.

### No decorative section separators

`// --- Helpers ---` and friends are banned. If a file needs signposting it needs splitting. Delete on sight — this belongs to the "unnecessary comments" category and the same judgment applies to the rest of the comment.

---

## Design rules — a violation here is usually a real defect

These are not formatting. Read the surrounding code before acting, and follow the skill's normal fix-vs-list line: fix when the change is provably behaviour-preserving and you can see every call site, list it when a reviewer would have to think.

### Passing a model *and* its own relationships

An `Agent` carries `->app`, `->company`, `->user`. A `Lead` carries `->app`, `->company`, `->user`, `->people`. Taking both the model and those references lets a caller hand in an app that disagrees with the model's own — a silent tenant mismatch.

```php
// WRONG
new ConnectSlackAgentAction(agent: $agent, app: $app, company: $company, user: $user, botToken: $token)->execute();

// CORRECT — derive from the entity
new ConnectSlackAgentAction(agent: $agent, botToken: $token)->execute();
```

Verify the relationship actually exists on that model before deriving — an `apps_id = 0` catalog row has no single company, and an append-only event may carry no user. When it genuinely isn't there, passing the reference separately is correct. The one legitimate reason to pass app/company alongside is to *look the entity up* (`getByIdFromCompanyApp`); once you hold the model, stop passing them.

Same rule for DTOs: a DTO holding an `Agent` must not also declare `app`/`company`.

### `findOrFail()` / `firstOrFail()` on a Kanvas model

Never. Use the `KanvasModelTrait` helpers — `getById`, `getByIdFromCompany`, `getByIdFromCompanyApp`, `getByUuid*`, `getByName` — which apply `notDeleted()` and throw the domain `ExceptionsModelNotFoundException`. A raw `findOrFail` will happily return a soft-deleted row. Fix directly; it is a behaviour fix, so say so in the report.

### Missing `overwriteAppService()`

Every queued job that takes an `Apps` starts `handle()` with `$this->overwriteAppService($this->app);`, and **every** command under `app/Console/Commands/` that resolves a specific app calls it too — once after resolving, or per-iteration inside the loop. Both need `use Baka\Traits\KanvasJobsTrait`.

Without it the worker keeps the previous job's Bouncer scope and container-bound app. The failure is invisible: a `ModelNotFoundException: Role` thrown deep inside `CreateChannelAction`, usually swallowed by a well-meaning `try/catch` into a "no results" return, so the command exits `0` having done nothing for 90 tenants. Add the line — do not reason about whether this particular command "probably only writes a custom field". The only exemptions are commands with no concrete app in scope at all: `apps_id = 0` catalog syncs, row-chunk backfills, ledger maintenance, pure queue dispatchers.

### A Spatie `Data` DTO on a queued job

If a `ShouldQueue` job stores a `Spatie\LaravelData\Data` DTO that holds Eloquent models or model interfaces, it will fatal in the worker — the serializer flattens `app` to a dynamic `$appsId` and the typed property stays uninitialized. Tell: a `Creation of dynamic property ...::$xxxId is deprecated` warning right before `Typed property X must not be accessed before initialization`.

The fix is structural — take models and primitives on the job, rebuild the DTO inside `handle()`, pass `Carbon` as an ISO string. Flag it as a bug even when you list rather than fix it; it is a guaranteed production failure, not a style preference.

### Tenant scoping

Manual `where('companies_id', ...)` where `fromCompany()` exists, a re-invented `scopeForApp`, or an `apps_id IN (0, x)` union on an entity that is not a platform-global catalog. The last one is how cross-app leaks happen — only `AgentType` and `ToolCategory` legitimately ship platform globals, via an explicitly named `scopeFromAppOrGlobal`.

### Unguarded remote fetch

Any server-side fetch of a URL that user input can influence goes through `SafeUrlFetcher::fetch()` or is gated by `SafeUrl::assertSafe()` first. A raw `file_get_contents($url)` / `Http::get($url)` on a user-supplied URL is an SSRF finding, not a cleanup item — report it at the top.

### Workflow activity throwing on an expected skip

A `KanvasActivity` must not throw or `report()` for a business condition — empty message, AI mode off, nothing to sync. `executeIntegration` reports every escaping exception to Sentry, so one expected skip becomes hundreds of non-actionable events. Catch it in the `integrationOperation` closure and `return $this->failWorkflow([...])`, which marks it FAILED in the UI without the Sentry write. A plain early return is fine for a benign no-op not worth flagging.

### Model / GraphQL wiring

- JSON columns cast with `Baka\Casts\Json::class`, never `'array'`.
- `uuid` columns come from `Baka\Traits\UuidTrait` — delete any manual `Str::uuid()`.
- Notifications extend `Kanvas\Notifications\Notification`, never `\Illuminate\Notifications\Notification`.
- A model exposing `files:` in GraphQL needs `HasLightHouseCache` + `getGraphTypeName()` + an observer `updating()` that clears the cache. Missing the trait silently disables invalidation; uploads do not appear until the cache expires.
- Owned children (FK points only here, orphan would be invalid, back-relation is non-null in the schema) belong in `$cascadeDeletes`. Orphans fatal the resolver with `InvariantViolation`.
- GraphQL types expose the relation **or** the FK id, never both — and relation directives always name the method (`@belongsTo(relation: "company")`). Never expose `apps_id`.

### Tests are part of done

A new Action, Service, Observer, Job, mutation resolver, model behaviour, or bug fix without a test is unfinished work, regardless of how clean it is. If the diff adds one of those and adds no test, that goes in the report as a finding — near the top, since it is the thing most likely to block the PR. Migrations that only add a column, schema-only passthrough fields, and doc fixes are exempt.

---

## Reporting convention findings

Group them under their own heading. Two rules keep the section useful:

- **Count the mechanical ones, list the interesting ones.** "Reformatted 7 calls to the rule of 4" is one line. A missing `overwriteAppService()` gets its own numbered entry with the consequence spelled out.
- **Say which rule and where it lives** — "rule of 4, root `CLAUDE.md`" or "`tests/CLAUDE.md` bans `RefreshDatabase`". The developer may disagree with the rule, and they can only take that up with the right file.

If the diff is clean against the conventions, say which files you checked it against. That is worth more than silence, same as the comment category.
