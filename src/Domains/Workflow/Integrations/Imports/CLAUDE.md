# Integration import — the generic "import from an integration" layer

One mutation, one run table and one queued job that bring records from any connected integration
into Kanvas. A connector declares what it can import and how to import one record; this layer owns
everything else (validation, concurrency, chunking, counting, status).

References: NetSuite (`src/Domains/Connectors/NetSuite/Importers/`: chunked with a `choices` option, a
single-run one, a follow-up import) and Salesforce (`src/Domains/Connectors/Salesforce/Importers/`:
organizations with an opt-in `then_people` follow-up, people refused while organizations run, used by
both GraphQL and `kanvas:salesforce-backfill`).

## The contract

A connector's handler (`Baka\Contracts\BaseIntegration`) overrides `importables()` and returns a list
of `Importable` (`entity`, `label`, `importer` class, `options`). The default is `[]`, which means
"imports nothing". `importables()` is declared in code and must never call the external system: the
client reads it on every `IntegrationsCompany` list.

| Piece | Role |
|---|---|
| `Importable` | One entity the integration can import. `entity` is the key the client sends (`products`) and the one the run table groups by. |
| `ImportOption` | One input of that entity. `type` is `ImportOptionTypeEnum` (`choices`, `boolean`, `string`); `multiple` only matters for `choices`. |
| `ImportChoice` | A value the external system offers for a `choices` option (`value`, `label`, `suggested`). `suggested` is a hint, never a rule. |
| `ChunkedImporter` | `list($options)` returns every record up front, `importOne($item, $options)` imports one and returns `ImportOutcomeEnum` (created / updated / skipped), `itemId($item)` names it for the error list. |
| `SingleRunImporter` | `run($options)` does the whole job in one go and returns the run's `summary`. For work that is one call or one search per scope (stock), not a record loop. |

Hooks on both (`IntegrationImporter`), all optional:

- `prepare(array $options): array` runs **synchronously in the mutation, before anything is queued**.
  Validate and normalise here (resolve a default saved search, map locations, refuse a bad id) so a
  wrong request fails in the response instead of as a `FAILED` run minutes later. What it returns is
  what the run stores in `options`.
- `choices(string $option): list<ImportChoice>` backs `integrationImportOptionChoices`. Called when the
  selector opens, so it may call the external system.
- `completed(IntegrationImport $run): ?array` runs after the last chunk of a chunked import, once the
  run is already `COMPLETED` (so a follow-up it starts is not blocked by this run). A returned array
  becomes the run's `summary`: NetSuite products start the stock import and report
  `{stock: queued|skipped}`, Salesforce organizations start people when `then_people` is set and report
  `{people: queued|skipped}`. Catch the start's `ValidationException` and report it as `skipped`; any
  other exception is `report()`ed by the job and the run stays `COMPLETED` with summary
  `{follow_up_error: <message>}`, since the records are already in.

`importOne()` **throws on a record it cannot import**. The job catches it, counts it in `failed`, keeps
the message in `errors` and moves on. Do not catch inside `importOne()` to "be safe": a swallowed
failure is invisible. Reserve a returned `SKIPPED` for records that are fine and deliberately left
alone (an existing NetSuite customer).

Items from `list()` are cached between chunks, so they must serialise: ids or plain arrays, never
models or clients. The first job imports chunk 0 from memory and caches every later chunk of
`CHUNK_SIZE` items under its own key, `integration-import-items:{run id}:{chunk number}`; the run's
`total` says how many there are. A continuation reads only its own key and forgets it once processed,
so no job unserialises the whole list. The keys live 24 h and each chunk refreshes the remaining ones,
so a long run never outlives its records. A missing key fails the run with "lost its record list".

## Adding an importer to a connector

1. Write `Importers/{Entity}Importer.php` extending `ChunkedImporter` or `SingleRunImporter`. The
   constructor receives `IntegrationsCompany $integrationCompany` and `Users $user`; use `$this->app()` and
   `$this->company()` rather than the container, since the job runs with no request.
2. Declare it in the handler's `importables()`, in the order a first import should run (the client
   renders them in that order).
3. If an option is `choices`, implement `choices()`; if a value needs normalising or a prerequisite
   checked, do it in `prepare()`.
4. Test it in `tests/Connectors/...` through `StartIntegrationImportAction` + `RunIntegrationImportJob`,
   not by calling `importOne()` alone, so the counting and the error path are covered.

Importer classes are built through `AllowedClass::resolve($class, IntegrationImporter::class)`, so a
class that does not extend `IntegrationImporter` is refused.

## The run: `integration_imports`

`IntegrationImport` (workflow connection) is one row per run: `status` (`queued`, `running`,
`completed`, `failed`), `total`, `processed`, `created`, `updated`, `skipped`, `failed`, `errors`,
`summary`, `error` (why the whole run died), and the three timestamps.

- **One run at a time per (company, integration, entity).** `StartIntegrationImportAction` takes a
  short cache lock and checks `IntegrationImport::inProgressFor()` inside it; a second start, or one
  that times out waiting for the lock, is a `ValidationException`. Two entities of the same integration
  may run together unless an importer's `prepare()` says otherwise (Salesforce people wait for
  organizations).
- **Run lookups are company + integration scoped, not app scoped.** `inProgressFor()` and
  `latestFor()` match `companies_id`, `integrations_id` and `entity`, so two rows of the same
  integration for one company share a lock and a status.
- **Staleness.** A `queued`/`running` run whose `updated_at` is older than
  `IntegrationImport::STALE_AFTER_HOURS` (3) stops blocking, because a worker killed mid-run never marks
  it failed. Each chunk saves the row, so a live run keeps refreshing it.
- **Errors are capped at 50** (`MAX_ERRORS`); `failed` keeps counting past that.
- **The error label** is `itemId($item)`. If `itemId()` itself throws (a malformed record), the label
  falls back to the position, `#<index>`, so that record fails alone and the run goes on.
- **Chunks** are `RunIntegrationImportJob::CHUNK_SIZE` (100) records per job, each queuing the next on
  the `imports` queue. They stay sequential because the counters are read-modify-write. The job calls
  `overwriteAppService($run->app)` first, as every app-scoped job must. If the cached list expired
  between chunks the run fails with "lost its record list"; starting again is safe because imports are
  resyncs. Each continuation re-checks `isImportable()` first: if the integration was deactivated or
  removed mid-run, the run fails with "The integration was disconnected during the import." and its
  cached chunks are dropped.
- `tries` is 1: a retried chunk would double-count. A job-level failure goes through `failed()` and
  marks the run `FAILED` with the exception message.
- **A worker killed mid-chunk** never reaches `failed()` until the queue gives up on the job (its retry
  window), so the run stays `RUNNING` until then and is marked `FAILED` after. There is no resume:
  start the import again.

## GraphQL surface

Schema: `graphql/schemas/WorkFlow/integrationImports.graphql` and the `importables` field of
`IntegrationsCompanies` in `integrations.graphql`. Resolvers: `app/GraphQL/Workflow/{Queries,Mutations}/Integrations/IntegrationImport*.php`.

| Operation | What it does |
|---|---|
| `IntegrationsCompanies.importables` | `IntegrationsCompany::getImportables()`: entities, labels, options and each entity's `last_import`. Empty unless the integration is active. Never calls the external system. |
| `integrationImportOptionChoices(integration_company_id, entity, option)` | The `choices` of one option, straight from the importer. |
| `integrationImport(integration_company_id, entity, options)` | Validates, runs `prepare()`, creates the run in `QUEUED`, dispatches the job. |
| `integrationImportStatus(integration_company_id, entity)` | The latest run of that entity, or `null`. For polling. |

Rules every entry point shares:

- The row is loaded with `IntegrationsCompany::getManageable($id, $app)` and the user must be a member
  of the row's company (`ResolvesImportableIntegration`, through
  `CompaniesRepository::userMemberOfCompany()`). Only the app owner skips membership; an admin does
  not, because the admin role spans the app while the row belongs to one company. A missing row,
  another app's row and a company the user is not in all answer `Integration not found.`, so the
  response never says what exists.
- `options` must be an object (`options must be an object.`).
- The integration must be active (`is_active` and status `active`), else `This integration is not
  active.`. A connection whose `setup()` failed is stored as `failed` and cannot import.
- `StartIntegrationImportAction` rejects an unknown entity (listing the valid ones), an undeclared
  option, a missing required one and a wrong type, and drops `null`, `''` and `[]` options before
  checking.

## What stays out

- **Odoo** still has its own copy, `OdooBackfillImportJob`; migrate it onto this layer instead of
  keeping a second record loop (see `src/Domains/Connectors/CLAUDE.md`, "Known duplication").
- **Shopify** has no importer yet.
- No cancel, no per-record retry and no history list: the table keeps every run, but the API only
  exposes the latest per entity.
