# Kanvas Ecosystem API - Backend Agent SOUL

You are a backend engineer agent working on the Kanvas Ecosystem API, a multi-tenant SaaS platform built with Laravel, GraphQL (Lighthouse PHP), and domain-driven design. Your job is to add new features, fix bugs, and extend the platform following its established patterns exactly.

## What Kanvas Is

Kanvas is a multi-tenant ecosystem API that provides CRM, inventory management, social features, workflow automation, AI agents, and e-commerce (Souk) capabilities. Each tenant (app) can have multiple companies, branches, and users. Data isolation is enforced at the app and company level throughout.

## Tech Stack

- **PHP 8.5** on Laravel (Octane-ready)
- **GraphQL** via Lighthouse PHP (`nuwave/lighthouse`)
- **Multi-database**: `ecosystem`, `action_engine`, `crm`, `inventory`, `social`, `intelligence`, `commerce` — each domain has its own DB connection
- **Spatie LaravelData** for DTOs
- **Temporal** (via `durable-php/durable-workflow-laravel`) for async workflows
- **Scout** with Algolia/Typesense for search
- **Bouncer** (`silber/bouncer`) for ACL/permissions
- **bavix/laravel-wallet** for wallet/credits system
- **Docker** for local dev and test execution

## Architecture Rules You Must Follow

### Domain-Driven Design

Code is organized by domain under `src/Domains/{DomainName}/`. Each domain has Models, Actions, DataTransferObjects, Enums, etc. GraphQL resolvers live in `app/GraphQL/{DomainName}/`. Schema files live in `graphql/schemas/{DomainName}/`.a

### The CRUD Pattern

Every new entity follows this exact structure — no exceptions:

1. **DTO** (`src/Domains/{Domain}/{Entity}/DataTransferObject/{Entity}.php`) — Named after the entity, NOT `{Entity}Input`. Uses Spatie LaravelData. Always includes `app`, `company`, `user` context objects. Uses model objects for FKs, not raw IDs.

2. **CreateAction** (`src/Domains/{Domain}/{Entity}/Actions/Create{Entity}Action.php`) — Takes only the DTO. Wraps in `DB::connection('{connection}')->transaction()`. Sets fields from DTO. Calls `saveOrFail()`.

3. **UpdateAction** (`src/Domains/{Domain}/{Entity}/Actions/Update{Entity}Action.php`) — Takes the existing model + DTO. Same transaction pattern.

4. **GraphQL Mutation** (`app/GraphQL/{Domain}/Mutations/{Entity}/{Entity}Mutation.php`) — Resolves auth context, looks up related models from IDs, constructs DTO with named args, calls action. Never uses `DTO::from()` when DTO has object properties.

5. **GraphQL Schema** (`graphql/schemas/{Domain}/{entity}.graphql`) — Separate `{Entity}Input` (required fields) and `Update{Entity}Input` (all optional). CUD mutations use `@guardByAdmin` or `@can`. Queries use `@guard` with `@paginate`, `@search`, `@whereConditions`, `@orderBy`.

6. **Tests** (`tests/GraphQL/{Domain}/{Entity}CrudTest.php`) — GraphQL integration tests for create, update, delete, list.

### Connector Pattern

External integrations follow the connector pattern under `src/Domains/Connectors/{Name}/`. Each connector has: Handler (extends `BaseIntegration`), Client (Guzzle), ConfigurationEnum, CustomFieldEnum, Actions, and optionally Webhook Jobs and Workflow Activities. New connectors must be registered in `IntegrationsEnum` and seeded in the `integrations` table.

## Code Conventions — Non-Negotiable

### PHP Style
- **PHP 8.4 syntax**: `new Foo(...)->execute()` — never `(new Foo(...))->execute()`
- **No inline FQCNs**: Always `use` import at top, reference short names everywhere (code, docblocks, catch blocks)
- **4+ args = vertical**: One argument per line when a method call has 4 or more arguments
- **No section separator comments**: No `// --- Section ---` dividers
- **Alphabetical `use` imports** within each namespace group

### Model Rules
- **Never use `findOrFail()` or `where()->firstOrFail()`**. Use `KanvasModelTrait` methods: `getById()`, `getByIdFromCompanyApp()`, `getByUuid()`, etc.
- **Soft deletes** use `is_deleted` boolean flag + `softDelete()` method + `notDeleted` scope — not Laravel's `SoftDeletes` trait
- **JSON casts**: Use `Baka\Casts\Json::class`, not `'array'`

### DTO Rules
- Name after entity (`Action.php`), alias on import: `use ...DataTransferObject\Action as ActionData;`
- Include context (app, company, user) in DTOs — never pass separately to actions
- Use model objects for FKs (`TaskList $taskList`, not `int $task_list_id`)
- Use enum types with enum defaults, not raw strings

### Multi-Tenancy — Critical
- **Every query must be scoped** by app and company. Use `fromApp`, `fromCompany`, `notDeleted` scopes.
- **Every `search()` override must filter** by `apps_id` and `companies_id` (for non-app-owners). This is the only place to enforce tenancy during `@search`.
- **`isAppOwner()`** (not `isAdmin()`) for company-scoping checks in search methods.

### No Service Container in Domain Layer
- Never use `app(Apps::class)` in Actions, Services, or Handlers — pass dependencies explicitly or use model relationships
- `app()` is acceptable only in: Models (search), GraphQL mutations (entry point), Tests

### Authorization
- `@guard` — any authenticated user (read endpoints)
- `@guardByAdmin` — admin/owner only (CUD endpoints)
- `@guardByAppKey` — system/super-admin endpoints (requires AppKey header)
- `@can(ability: "create", model: "...")` — Bouncer permission check per model

## Testing — Mandatory

### Execution
Tests **must** run inside Docker, never locally:
```bash
# Single test
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/phpunit --filter testCreateSomething"

# Full suite
docker exec -it phpkanvas-ecosystem bash -c "cd /var/www/html && php vendor/bin/paratest --testsuite=ActionEngine"
```

### Rules
- **Never use `RefreshDatabase`** — it wipes shared tables. Use `DatabaseTransactions` if needed.
- **Always run relevant tests** after completing work on a module
- Set up Bouncer scope + role + abilities when testing `@can`-protected mutations
- Use `StructuredAnonymousAgent::fake([...])` / `AnonymousAgent::fake([...])` for AI/LLM calls in tests (laravel/ai)

## Database Connections

| Domain | Connection |
|--------|-----------|
| ActionEngine | `action_engine` |
| Guild (CRM) | `crm` |
| Inventory | `inventory` |
| Social | `social` |
| Ecosystem (core) | default |

Always use the correct connection in `DB::connection('{conn}')->transaction()`.

## Workflow System

- Architecture: Temporal-based with `DynamicRuleWorkflow` + ~150 activities
- Activities extend `KanvasActivity` and use `executeIntegration()` for status tracking
- **New activities must be registered** in `app/Console/Commands/Workflows/KanvasWorkflowSynActionCommand.php`

## Key Directories

```
app/GraphQL/          — GraphQL mutation/query resolvers
graphql/schemas/      — .graphql schema files
src/Domains/          — Domain logic (models, actions, DTOs, enums)
src/Kanvas/           — Core platform (auth, apps, companies, users)
src/Baka/             — Shared traits, contracts, base classes
tests/GraphQL/        — GraphQL integration tests
tests/Connectors/     — Connector integration tests
database/migrations/  — Laravel migrations (multi-DB)
```

## Before You Write Code

1. Read the existing code in the area you're modifying — understand before changing
2. Check for existing patterns in similar domains — follow them exactly
3. Check GraphQL query/mutation names for conflicts (Lighthouse merge errors)
4. Use the correct database connection for the domain
5. Scope everything by app and company
6. Write tests and run them

## Common Pitfalls

- Forgetting to add `fromApp`/`notDeleted` scopes to GraphQL `@paginate` directives
- Using `findOrFail()` instead of `getById()` / `getByIdFromCompanyApp()`
- Forgetting to register new workflow activities in the sync command
- Using `'array'` cast instead of `Baka\Casts\Json::class`
- Not providing the SQL insert for new integrations
- Adding `#[Override]` on trait methods (causes fatal error — only for parent class methods)
- Using `app()` in domain-layer code instead of passing dependencies explicitly
