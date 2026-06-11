---
name: sync-kanvas-db
description: >
  Runs pending database migrations across ALL Kanvas Laravel API databases on
  the local Docker environment. Invoke whenever the user says "sync db",
  "run migrations", "sync database", "pull from dev", "run pending migrations",
  or when any page returns a 500/GraphQL error caused by a missing table or
  column. Also invoke proactively after any `git pull` or `git merge` from
  dev/main when migration files have changed.
---

# sync-kanvas-db

Applies pending migrations to every database inside the `phpkanvas-ecosystem`
Docker container. Kanvas uses multiple separate MySQL databases, each with its
own migration subfolder and Laravel connection name.

## Container and paths

- **Container**: `phpkanvas-ecosystem`
- **API repo (host)**: `/Users/mcwhite/Documents/docker_projects/kanvas/kanvas-ecosystem-api`
- **Migrations root (inside container)**: `database/migrations/`

## Database map

Each subfolder uses a different Laravel connection. Run migrations for the
subfolder with the matching `--database` flag.

| Migration path | `--database` flag | Notes |
|---|---|---|
| `database/migrations/` (root) | *(omit — default mysql)* | Core platform tables |
| `database/migrations/ActionEngine/` | `--database=action_engine` | |
| `database/migrations/Event/` | `--database=event` | |
| `database/migrations/Guild/` | `--database=crm` | CRM module |
| `database/migrations/Intelligence/` | `--database=intelligence` | AI agents, tools, deployments |
| `database/migrations/Inventory/` | `--database=inventory` | |
| `database/migrations/Social/` | `--database=social` | |
| `database/migrations/Souk/` | `--database=commerce` | Commerce/orders |
| `database/migrations/Subscription/` | *(omit — default mysql)* | Subscription tables in main DB |
| `database/migrations/Workflow/` | `--database=workflow` | |

## Step 1 — Find pending migrations

Run this for each path you care about (or all of them after a big pull):

```bash
# For a specific path:
docker exec phpkanvas-ecosystem php artisan migrate:status \
  --path=database/migrations/Intelligence --database=intelligence 2>/dev/null \
  | grep "Pending"

# For the default-connection paths (root, ActionEngine, Subscription):
docker exec phpkanvas-ecosystem php artisan migrate:status \
  --path=database/migrations 2>/dev/null | grep "Pending"
```

Filter results to only entries under the path you queried — artisan sometimes
shows migrations from the whole project.

## Step 2 — Run each pending migration individually

Run in chronological order (filename = timestamp order), one at a time.

```bash
docker exec phpkanvas-ecosystem php artisan migrate \
  --path=<migration_file_path> \
  [--database=<connection>] \
  --force 2>&1
```

### Handling failures gracefully

These errors mean the schema already diverged on your local DB — skip, don't
abort:

| Error | Action |
|---|---|
| `Column already exists` (1060) | Schema already present. Fake-mark (Step 3) and continue. |
| `Table already exists` (1050) | Table already present. Fake-mark (Step 3) and continue. |
| `Duplicate key name` (1061) | Index already exists. Fake-mark (Step 3) and continue. |
| `Field 'uuid' doesn't have a default value` (1364) | Seed migration omits `uuid`. Fix: add `'uuid' => \Illuminate\Support\Str::uuid()->toString()` to each insert in the migration file, then re-run. |
| `Column not found` (1054) after `->after(...)` | The referenced column doesn't exist locally. Fix: remove the `->after('...')` call in the migration file, then re-run. |
| Any other error | Log it and continue to the next migration. |

When editing a migration file to fix a local divergence, make the change
minimally (remove `->after()`, add `uuid`, wrap in `hasColumn()` guard). Don't
commit these fixups unless asked.

## Step 3 — Fake-mark already-applied migrations

If the schema change already exists but the migration record doesn't, insert a
fake record so Laravel stops reporting it as Pending.

**IMPORTANT**: you must insert into the correct DB's `migrations` table — match
the `--database` connection from the table above.

```bash
# Example for intelligence DB:
docker exec phpkanvas-ecosystem php artisan tinker --execute="
DB::connection('intelligence')->table('migrations')->insertOrIgnore([
  'migration' => '2026_05_15_000000_migration_name_without_php',
  'batch' => 99
]);
"

# Example for default mysql DB:
docker exec phpkanvas-ecosystem php artisan tinker --execute="
DB::table('migrations')->insertOrIgnore([
  'migration' => '2026_05_15_000000_migration_name_without_php',
  'batch' => 99
]);
"
```

Use only when the schema change definitely exists but the migration keeps
showing as Pending.

## Step 4 — Report

After processing all paths:

```
Kanvas DB sync complete
✓ Ran:     <list>
⚠ Skipped: <list with reason>
✗ Failed:  <list with error>
```

If nothing was pending, say so and exit cleanly.

## Common missing tables (reference)

These are frequently missing on local DBs that haven't been fully migrated:

| Table | DB | Migration file |
|---|---|---|
| `agents_kanvas_modules` | `intelligence` | `2026_05_15_040000_create_agents_kanvas_modules.php` |
| `agent_daily_cycles` | `intelligence` | `2026_05_15_080000_create_agent_daily_cycles.php` |
| `agent_sleep_phases` | `intelligence` | `2026_05_15_090000_create_agent_sleep_phases.php` |
| `nervous_system_tool_categories` | `intelligence` | `2026_05_15_120000_create_nervous_system_tool_categories.php` |
