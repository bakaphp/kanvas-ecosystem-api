---
name: kanvas-backend-review
description: Reviews PHP/Laravel backend code for duplicated methods, overly complex logic, unnecessary comments, and violations of the repo's own CLAUDE.md conventions (the rule of 4, named arguments, no inline FQCNs, tenant scoping, `overwriteAppService`), fixes what it finds, verifies the tests still pass, and reports what changed and why it mattered. Use this whenever the user asks for a backend code review or cleanup, a PHP or Laravel review, a pre-PR or pre-commit pass, or says things like "review my changes", "clean this up before I push", "remove the duplicate code", "simplify this", or invokes /kanvas-backend-review. Also use it when the user is about to open a pull request on a Laravel service, resolver, job, workflow action, or repository and wants the obvious problems fixed rather than listed.
---

# Kanvas Backend Review

A cleanup pass over a multi-tenant Laravel/GraphQL backend. It looks for four things — duplicated methods, overly complex logic, unnecessary comments, and code that breaks the repo's own conventions — **fixes them**, verifies nothing broke, and then reports what changed.

The scope is deliberately narrow. Broad "improve everything" passes produce sprawling diffs nobody can review. Four categories, actually fixed, with a changelog the developer can skim before pushing, is something they can trust.

The fourth category is not style pedantry. This repo writes its rules down in `CLAUDE.md`, php-cs-fixer enforces almost none of them, and the ones it misses are exactly the ones that cost something later — a positional `null` that silently rebinds when a parameter is added, a job missing `overwriteAppService()` that quietly does nothing for ninety tenants.

The single most valuable thing this pass produces is not the cleanup. It is the latent bug you find *while* deduplicating — the two copies that were supposed to be identical and weren't. Stay alert for those; they justify the whole exercise.

## Step 1 — Decide what to work on

Default to the developer's uncommitted work, because that is what they are about to push:

```bash
git status --short
git log --oneline -10
git diff HEAD --stat
git diff HEAD
```

Adjust when the situation calls for it:

- The user passed a path (`/kanvas-backend-review app/Services/Billing`) → work through that file or directory in full.
- `git diff HEAD` is empty → fall back to `git diff origin/main...HEAD` (try `develop` or `master` if `main` does not exist) and say which range you used.
- Both empty → tell the user there is nothing staged or unstaged and ask what to point at. Do not silently sweep the whole repo.

Only PHP matters here. Leave migrations, generated GraphQL schema files, `vendor/`, and lock files alone unless the user explicitly asks.

**Notice the state of the repo while you are in there.** The `git log` above is not ceremony. Developers running several sessions at once end up with surprises: a change they think is uncommitted already landed inside an unrelated commit, a branch is behind its remote, half the feature is untracked files that a `git commit -a` would miss. If you spot something like that, it goes at the top of the report — it affects what they are about to push, which is more urgent than any comment you deleted.

If the repo has no git at all, say so and ask before editing. There is no undo.

## Step 2 — Read enough context to be right

You are about to change this code, so approximately right is not good enough.

**Load the conventions for the trees the diff touches, before reading the code.** The root `.claude/CLAUDE.md` always applies; sub-directory `CLAUDE.md` files apply additively to their own tree, and new ones get added over time — so discover them rather than assuming:

```bash
cat .claude/CLAUDE.md
find . -name CLAUDE.md -not -path './vendor/*' -not -path './node_modules/*'
```

Read the ones whose directory contains a file in your diff — a change under `src/Domains/Connectors/**` is bound by `src/Domains/Connectors/CLAUDE.md` as well as the root file, and anything under `tests/**` by `tests/CLAUDE.md`. Reviewing against the root file alone is the usual way a convention violation survives this pass. If the diff scaffolds a CRUD, a connector, or an `@search` query, the matching skill in `.claude/skills/` is the spec that change should have followed — read it too.

- Read the full file for every method you intend to touch, not just the diff hunk. A method that looks convoluted in a diff is often fine in context.
- For a suspected duplicate, locate the other copy before acting:

  ```bash
  grep -rn "function methodName" app/ src/ --include=*.php
  rg -n "distinctive line of logic" --type php
  ```

- **When you find two copies, diff them carefully before merging.** Near-identical is the interesting case. If they differ in a way that isn't obviously intentional — one filters by type and the other doesn't, one guards a null and the other doesn't — you have found a bug, not just duplication. Work out which behaviour is correct, take that one, and make the divergence the headline of that finding.
- Before extracting into a trait, enum, or action, check whether one already exists. In this codebase the most common duplication is reimplementing something the framework or a base class already provides. The fix is to *use* it, not to invent a second abstraction beside it.
- Before deleting a comment, work out whether it explains a *why* the code cannot express. Those stay.

If you cannot tell whether a pattern is deliberate, leave it and say so. Guessing wrong costs the developer more than the duplication did.

## Step 3 — What to look for

Read `references/php-laravel-smells.md` for the concrete patterns, examples, and the list of things that look like smells but are not, and `references/kanvas-conventions.md` for the house rules and how to fix each one. The short version:

**Duplicated methods.** Identical or near-identical bodies across classes; logic copy-pasted between a service and its resolver or job; a private helper reimplementing a trait, base class, or Eloquent scope; two methods differing only by a constant; a mapping that belongs on the enum it maps to.

**Overly complex logic.** Deep nesting where guard clauses would flatten it; one method doing fetch + transform + persist + notify; long boolean conditions wanting a name; a `match` that keeps growing; work repeated per-iteration that could be hoisted; a query re-run on every call that could be memoized; hydrating full Eloquent models to read two columns; an abstraction that leaks its concrete type through a supposedly generic interface; dead parameters and dead variables.

**Unnecessary comments.** Comments restating the code, commented-out code, empty docblocks adding nothing beyond the signature, stale comments describing behaviour that has since changed, `TODO`s with no owner or ticket, decorative `// --- Section ---` dividers.

**Convention violations.** What `CLAUDE.md` mandates and the fixer does not check. The rule of 4 — any call or signature with 4+ arguments goes one per line, 3 or fewer stays inline, native functions exempt — is the most-violated one, so check every call in the diff including `new Foo(...)` and test arrange blocks. Then: positional `null`s that should be named arguments; inline FQCNs in code, docblocks, and `catch` blocks; `(new Foo(...))->x()` instead of PHP 8.4's `new Foo(...)->x()`; `findOrFail()` where a `getByIdFromCompanyApp()` belongs; a job or command missing `overwriteAppService()`; a Spatie `Data` DTO stored on a queued job; a model passed alongside relationships it already carries; `'array'` casts where `Baka\Casts\Json` belongs; an unguarded fetch of a user-influenced URL; a workflow activity throwing on an expected skip; new Actions or resolvers shipped without tests.

## Step 4 — Fix it

Work through the findings and apply the fixes. Do the mechanical, zero-risk changes first (comments, dead code), then extractions, then restructuring — if something goes wrong later in the pass, the safe wins are already banked.

**Fix directly, without asking, when the change preserves behaviour and you can see the whole blast radius:**

- Comment noise, commented-out code, empty docblocks, dead parameters and variables, section-divider comments.
- The mechanical conventions: rule-of-4 formatting, named arguments replacing positional `null`s, hoisting inline FQCNs into `use` imports, PHP 8.4 instantiation syntax. Confine these to code the diff already touches — reformatting untouched files buries the real findings.
- A missing `overwriteAppService()` in a job or command, and `findOrFail()` → the `KanvasModelTrait` getter. Both change behaviour for the better; fix them and say so in the report rather than leaving a known-broken tenant scope in place.
- Flattening nesting into guard clauses; naming a long boolean condition.
- Collapsing duplicated methods behind one accessor or moving a mapping onto its enum.
- Extracting a repeated method into an existing trait or base class, updating every call site.
- Replacing a hand-rolled helper with the framework affordance that already does it.
- Hoisting repeated work out of a loop, memoizing a repeated query, selecting columns instead of hydrating models.

**Do not change these on your own — list them instead:**

- Public API shape: signatures other code calls, GraphQL schema, event payloads, job constructor arguments.
- Anything touching money, auth, or tenant scoping unless the fix is provably identical. A wrong refactor there is a billing bug or a data leak.
- New files or new abstractions the codebase does not already have. Introducing a `PricingAction` is an architecture decision, not a cleanup.
- Dense logic with no test coverage where you cannot be certain the refactor is equivalent.
- Anything that trades a real behaviour change for tidiness — collapsing "first non-null across records" into "whatever the first record had" to delete a small method is a bad trade, even though the diff looks nicer.

The line is: *would a reviewer need to think hard about whether this is still correct?* If yes, describe it instead of doing it.

**The one exception is a bug you uncovered while merging duplicates.** There, doing nothing means knowingly leaving broken code. Fix it, and say plainly in the report which behaviour changed and why the new one is right — never let a behaviour change ride along unmentioned inside a cleanup.

### Verify before reporting

A cleanup that breaks the build is worse than no cleanup. Re-run the checks as you go, not only at the end, so a failure points at the change that caused it:

```bash
php -l path/to/File.php
./vendor/bin/phpunit --filter RelevantTest
./vendor/bin/php-cs-fixer fix --dry-run --diff path/to/File.php
./vendor/bin/phpstan analyse path/to/File.php
```

Run what covers what you touched. If the project validates a GraphQL schema or has a coverage gate, run those too.

Style is checked with php-cs-fixer, not Pint — check `composer.json` scripts and the repo root for `.php-cs-fixer.php` / `.php-cs-fixer.dist.php` to get the invocation right, since some repos wrap it in a composer script such as `composer cs-fix`. Run it in dry-run mode as a check; a formatting sweep across files you did not otherwise touch buries your real changes in noise.

If a change breaks a test, revert that specific change and move it to the "considered and rejected" list with the failure as the reason. Do not leave broken code and mention it in passing. If a method you restructured has no coverage at all, say so — that is the developer's cue to look harder at that hunk.

Never commit, stage, or push. Leave everything in the working tree so the developer reads the diff and decides.

## Step 5 — Report what you did

The report is a changelog written to be read once, quickly, by someone about to push. Past tense, numbered continuously across sections, and — the part that makes it worth reading — each entry says why the change mattered, not just what moved.

Match this shape and this voice:

```
Review done. Found and fixed 12 real issues — plus one thing about your git state you should know before pushing.

⚠️ Your enum change is already committed
`case YUSEN = 'yusen';` landed in commit `4c07ff355 "update agent pulse"` (Aug 24, 16:10) — swept into an
unrelated commit by a parallel session. Not a problem, but only the 19 untracked files are left to push.

## Duplicate methods

1. `describe()` / `variantId()` both did `load()` + `isset(...) ? ... : null`. Collapsed behind one private
   `variant()` accessor.
2. `resolveFilesystemId()` / `resolveFileName()` both looped `uploadedFiles()`. Merged — and this was hiding a
   bug: `resolveFilesystemId` picked the first `.xml`, `resolveFileName` picked the first file of any type. An
   email with a signature image attached would report the wrong filename against the processed file's contents.
3. `missingType()` moved onto the enum as `DiscrepancyTypeEnum::missingFor()` — the mapping now lives beside
   the cases it maps to.

## Unnecessary complexity

4. `KanvasWarehouseQuantitySource` leaked through the comparator: `diff()` and `summarize()` both took the
   concrete class, undercutting the "source-agnostic" claim. `variant_id` is now stamped post-hoc in
   `withVariantIds()`. Side benefit: it resolved a variant for every balance line before; now only for rows
   that make the report.
5. `warehouseId()` re-queried on every call (up to 3×, each a DB hit when `primary_warehouse_id` is unset).
   Memoized.
6. `load()` held full Eloquent models for every variant. On a six-figure catalog that is a lot of hydrated
   models to answer "id and name". Now `select(['id','name',$matchField])` into plain arrays.

## Comments

I checked all 31 inline comments — none restate the code. They are external-system quirks (Manhattan re-sends
on ack timeout, `readOuterXml` namespace behaviour), non-obvious perf choices, or test arithmetic. Two fixes:

7. A stale docblock said the full report is "what the GraphQL mutation returns" — that mutation is deleted.
8. The `#[WorkflowAction]` description said "Parses the count, writes it and reports..." while the same
   sentence ended "it writes no stock." That description is what agents read when picking a workflow step, so
   a contradictory one is worse than none.

9. Added one comment: `str()`/`float()` take `mixed`, which looks like pointless defensiveness. It isn't —
   `$missing->child` returns `null` while a direct missing child returns an empty element, so the guard is
   load-bearing if `<SKU>` is ever absent.

## Conventions

Checked against root `CLAUDE.md` plus `src/Domains/Connectors/CLAUDE.md`, which both apply to this tree.

10. `ProcessYusenInventoryBalanceJob::handle()` never called `overwriteAppService($this->app)`. The worker
    keeps the previous job's Bouncer scope, so this would have resolved roles against whatever app ran last —
    the failure mode is a swallowed `ModelNotFoundException` and a job that exits clean having done nothing.
11. `InventoryDiscrepancyReport::build()` took the report *and* `$app` / `$company`, which the report already
    carries. A caller could pass a company that disagrees with the report's own. Now derived from the entity.
12. Four `catch (\Throwable $e)` blocks used inline FQCNs. Imported.

Also reformatted 7 calls and 2 signatures to the rule of 4 (`describeFrom()`, the two `assemble*` helpers, and
the `new InventoryBalanceLine(...)` construction in the parser), and swapped three positional `null`s in
`setConfiguration()` for named arguments.

## Verified

21/21 Yusen tests green after every step, coverage test green, schema valid, cs-fixer clean.

## Considered and rejected

Folding `InventoryBalanceLine::describeFrom()` into the constructor to delete a 4-param method. It works, but
it changes "first non-null across records" to "whatever the first record had" — a real behaviour change to
save one small method. Not worth it.
```

What makes that report work, and what to preserve when yours looks different:

- **Open with the count and the surprise.** One sentence: how many real issues, plus anything about the repo state or a bug found that the developer needs before pushing. Lead with the thing that changes what they do next.
- **Name symbols, not just line numbers.** `resolveFilesystemId()` is what they remember writing; `Webhook.php:212` is something they have to go look up. Give the path when the symbol is ambiguous, otherwise trust the name.
- **Every entry earns its place with a consequence.** "Merged two loops" is bookkeeping. "Merged two loops — and this was hiding a bug where an email with a signature image would report the wrong filename" is why they are reading. If you cannot say why a change mattered, it probably didn't, and it should not be in the list.
- **Report what you checked and left, not only what you changed.** "I checked all 31 inline comments — none restate the code" is worth more than silence: it tells them the category is genuinely clean rather than skipped. Counts make that credible.
- **Adding a comment is a legitimate finding.** When code looks like pointless defensiveness but is load-bearing, verify why and write the comment. Say what you verified.
- **Note side effects you did not go looking for** — a refactor that also cut per-row work, a dead field now surfaced usefully.
- **Close with what you rejected and why.** This is the section that earns trust in everything above it: it shows the line you were holding. One or two, with the real reason.
- **Cite the rule and the file it lives in.** "rule of 4, root `CLAUDE.md`" lets the developer argue with the rule in the right place if they disagree. Count the mechanical convention fixes in one closing line; give the ones with a consequence — a missing `overwriteAppService()`, a queued Spatie DTO — their own numbered entry.
- **Verification is numbers, not adjectives.** "21/21 tests green, schema valid, cs-fixer clean" — never claim a check you did not run.
- Sweep genuinely trivial items into one closing line of their section ("also dropped a dead `$label` param and some redundant `(string)` casts") rather than numbering them.
- If the code was already clean, say so in two sentences and change nothing. A no-op pass is a legitimate outcome and much better than inventing work.

Nothing is committed, so `git checkout -- <file>` is the developer's undo on any change they disagree with. Offer to revert specific ones if they push back.
