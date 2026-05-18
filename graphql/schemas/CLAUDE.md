# GraphQL Schemas — Kanvas Ecosystem API

Loads when work touches `graphql/schemas/`. For CRUD schema shape and `@search`, see skills `kanvas-crud` and `kanvas-search`.

## Schema files always live in a domain folder

Never loose at `graphql/schemas/` root. Always nested under a named folder like `graphql/schemas/Intelligence/agentType.graphql` or `graphql/schemas/Analytics/dashboard.graphql`.

## Authorization directives

- `@guard` — any authenticated user
- `@guardByAdmin` — admin/owner only (uses `isAdmin()` check)
- `@guardByAppKey` — app key (super admin / system) only
- `@can(ability: "create", model: "Kanvas\\Domain\\Models\\Entity")` — Bouncer ability check per model

Use `@can` for create/edit/delete on mutations that need model-level permissions; abilities must match the schema literals `create`, `edit`, `delete`. Tests with `@can` need Bouncer setup — see `tests/CLAUDE.md`.

## Don't expose FK ids when the relation exists

If a type has `@belongsTo(relation: "x")`, drop the matching `x_id` column. Generalizes to all FKs (apps_id, companies_id, users_id, plan_id, agent_id, etc.).

```graphql
# WRONG — duplicating
type NervousSystemPlan {
    agent_id: Int
    users_id: Int
    agent: Agent @belongsTo(relation: "agent")
    user: User @belongsTo(relation: "user")
}

# CORRECT — relations only
type NervousSystemPlan {
    agent: Agent @belongsTo(relation: "agent")
    user: User @belongsTo(relation: "user")
}
```

`apps_id` is **always** the current app in any tenant-scoped query — never expose it as a column or `@whereConditions` filter on `@guard`/`@guardByAdmin` queries. For super-admin dashboards that need cross-tenant visibility, use a separate `@guardByAppKey` query instead.

**Input types are the only exception:** create/update mutation inputs can carry raw `*_id` since the client doesn't have the model yet. Even there, prefer letting the resolver look up the model and pass it to the action's DTO as an object.

## Always name the relation method

When exposing an Eloquent relation in GraphQL, always pass `relation:` explicitly. Don't rely on Lighthouse's implicit field-name → method-name inference — it breaks on rename/alias and is harder to grep for.

```graphql
# WRONG — implicit inference
type Filesystem {
    settings: [FilesystemSettings!]! @hasMany
}

# CORRECT — explicit method name
type Filesystem {
    settings: [FilesystemSettings!]! @hasMany(relation: "settings")
}
```

Same for `@belongsTo(relation: "company")`, `@hasOne(relation: "primaryAddress")`, `@belongsToMany(relation: "roles")`, and `@method(name: "createdAt")`.

## Prefer `@paginate(scopes: [...])` over custom `builder:` resolvers

When a list query's filtering can be expressed as named scopes on the model, use `@paginate(model: ..., scopes: [...])` instead of a custom `builder:` resolver class. `fromApp` / `fromCompany` from `KanvasModelTrait` already handle the AppKey-vs-user-context conditional internally.

```graphql
# PREFERRED
extend type Query @guardByAdmin {
    ledgerEvents(...): [LedgerEvent!]!
        @paginate(
            model: "Kanvas\\NervousSystem\\Ledger\\Models\\Event"
            scopes: ["fromApp", "fromCompany", "recent"]
            defaultCount: 50
        )
}
```

Reach for a custom `builder:` only when constraints genuinely can't be expressed as scopes (dynamic field selection, runtime config lookups).

## Query naming

Check existing query names in `graphql/schemas/` before naming yours, to avoid Lighthouse "Duplicate definition" merge errors.

## Analytics scoping (mandatory)

Analytics/aggregation queries (counts, sums, distributions) MUST filter by `apps_id` AND `companies_id` at the base action level — no app-owner bypass, no exceptions. Add a cross-tenant leak test for every analytics endpoint.
