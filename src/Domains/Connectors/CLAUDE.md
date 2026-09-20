# Connectors — Kanvas Ecosystem API

Loads when work touches `src/Domains/Connectors/`. For the full scaffold pattern (Handler + Client + DTO + Enums + Webhook + Workflow + GraphQL + `integrations` row), invoke the `kanvas-connector` skill.

Per-connector `CLAUDE.md` (load when working in that connector's tree):
- [`Mcp/CLAUDE.md`](Mcp/CLAUDE.md) — **production debugging reference** for remote MCP servers: where every piece of state lives (grants, per-agent credentials, OAuth clients, the two cache tiers), what each log line means, a symptom playbook, verified vendor quirks (Google preview gates and Calendar's read-only scopes, Meta's refused registration, redirects, query-string keys), timeouts, how to add a server safely, and the known limits that are decisions rather than bugs.
- [`PiDev/CLAUDE.md`](PiDev/CLAUDE.md) — pi.dev coding-agent job runner: agent-scoped GitHub token/allow-list, 3-tier rules of engagement, Kanvas-owned job durability + poller, `Neuron/Tools/Coding/` tools.
- [`Intellicheck/CLAUDE.md`](Intellicheck/CLAUDE.md) — ID verification: the inbound base64 + `private_data.result` contract, the selfie in `facial.data.photoFace`, why a "folder" is a root message and the report must thread as a child, the `generate-id-verification` vs deprecated `after-id-verification` split, and the two DB rows without which firing a verb does nothing silently.
- [`WordPress/CLAUDE.md`](WordPress/CLAUDE.md) — publishing a Message as a wp/v2 post: the message body post structure + fallbacks, Application Password setup through the generic `integrationCompany` mutation, and why the scraper `Client` and the `RestClient` are unrelated.
- [`UniversalSeguros/CLAUDE.md`](UniversalSeguros/CLAUDE.md) — auto-insurance SDK + its `Providers/UniversalSegurosProvider` implementation of the `Kanvas\Insurance` contracts. Per-product emit scopes, QA chassis blocker, problem+json error shape.
- [`WaSender/CLAUDE.md`](WaSender/CLAUDE.md) — inbound WhatsApp: the three conversation shapes (lead DM / assistant DM / group) and how they route, the full `receiver_webhooks.configuration` key table, burst debouncing, which entity each workflow event carries (and why group traffic must never hit the DM event), and the lid-addressing + `slug`-vs-`uuid` foot-guns.
- [`TypeSafe/CLAUDE.md`](TypeSafe/CLAUDE.md) — TypeSafe Jev "System One": calibrated typed decisions (Noul/Choice/Score) standing in front of an LLM that stays as the fallback. The per-decision `OFF|SHADOW|LIVE` rollout switch, the jaggedness rules that decide what may and may not be asked (no math, no text, one judgement per question, adversarial inbound text), Noul-vs-Choice, and why the model id is pinned.
- [`Twilio/CLAUDE.md`](Twilio/CLAUDE.md) — inbound SMS/MMS: the consent halt, the shared burst debounce and its
  config table, why nothing in the webhook replies, and the support-mode delayed turn.
- [`Yusen/CLAUDE.md`](Yusen/CLAUDE.md) — 3PL Item Balance XML → discrepancy report: the exact POST Yusen makes (multipart vs raw body), why the connector writes no stock (a per-source warehouse double-counts `Variants::setTotalQuantity()`), the lot-summing assumption and its `multi_record_items` tripwire, and the synthetic-fixture rule.

## Known duplication — flagged, not yet resolved

### Salesforce and Odoo are structurally the same CRM connector

`Odoo/` was built as a fork of `Salesforce/`, so the following pairs are near-verbatim copies:

| Salesforce | Odoo |
|---|---|
| `Actions/Concerns/UpsertsByExternalId.php` | same |
| `Actions/PullOrganizationAction.php` / `PullPeopleAction.php` / `PullLeadAction.php` | same |
| `Activities/Push{Lead,People,Organization}Activity.php` | same |
| `app/Console/Commands/Connectors/*/…BackfillCommand.php` | same |
| `Jobs/…BackfillImportJob.php` | same |

The two backfill **jobs** are the strongest candidate to unify — the record loop, the
processed/failed counters, the per-record `try/catch → report()` and the summary log line are
byte-identical, differing only in the id key (`Id` vs `id`) and which `Pull*Action` each entity
type maps to. A shared abstract job taking those two as template methods would collapse both.

The three `Push*Activity` classes are **not** worth unifying — the framework discovers one class
per `#[WorkflowAction]` attribute, so that repetition is structural, not accidental.

Deliberately left alone for now (2026-09-12): merging would mean editing the live Salesforce
connector from an unrelated PR. Do it as its own change, with the Salesforce suite green, before
a third CRM connector lands and makes it three copies.

## Hard rules specific to this tree

### An inbound channel that answers must debounce — use the shared burst layer

A connector that runs an agent on inbound messages **must not** answer once per message. People send
three lines in a row; three independent agent turns means three replies, which is what SMS shipped to
customers before this existed. Both connectors that answer inbound today (WaSender, Twilio) go
through one implementation, and a third must not write a fourth:

| Piece | Where |
|---|---|
| chain + re-arm the debounce | `Social\Messages\Concerns\ChainsInboundBursts` |
| head registry + window comparison + prompt assembly | `Social\Messages\Services\MessageBurstService` |
| the delayed, supersede-guarded flush | `Social\Messages\Jobs\FlushMessageBurstJob` |
| **yours**: correlation keys + windows | a `BurstPolicy` |
| **yours**: what happens once it closes | a `BurstHandler` subclass |

The whole connector-side recipe is those last two plus one `fileIntoBurst()` call after the message is
filed. Correlation keys are what "one turn" means for your channel — WhatsApp uses album-id then
speaker, SMS uses the sender's number, Slack would use `thread_ts` then speaker.

Two rules that are not obvious:

- **The handler is instantiated by class name from the queued job**, so its constructor is `final` and
  anything it needs travels in `$params` — as **scalars**. `SerializesModels` only converts a
  top-level `QueueableEntity`, so an Eloquent model nested in that array is raw-serialized and comes
  back with stale attributes. Pass `receiver_id`, resolve it in the handler.
- **Chain before downloading media.** A download takes seconds and a message left unparented that
  long is adopted as head by the next part of the burst. A connector with media calls
  `attachToBurst()` / `armBurstClose()` itself instead of `fileIntoBurst()` — see
  `WaSender\Actions\BaseInboundMessageAction::fileIntoBurstWithMedia()`.

Email is the deliberate exception: it has real RFC threading headers (`In-Reply-To`/`References`) and
does not need a time-based debounce.

### A connector must not own a model or a table the platform reads

A connector is an adapter to someone else's service: client, DTOs, handler, activities, webhook jobs. The
moment it owns a **table** — and a model other domains read — the domain has leaked into the connector, and
every consumer now depends on a connector for its own data.

Put the model (and its status enum, the thing the table stores) in the domain that owns the concept, and
keep the behaviour in the connector if that is where it belongs. Precedents: `McpToolSnapshot` and
`McpAsyncJob` are `NervousSystem\Capability\Models\*` on `nervous_system_*` tables, while the jobs and
actions that drive them live in `Connectors/Mcp`; `CachedMcpConnector` sits in `Intelligence\Agents`.

Connector-local rows that are genuinely about the external service (credentials, cursors, per-tenant
settings) belong in `integrations`/`integrations_company` config or a custom field — not a new table.

### AgentRuntime is a primary domain, NOT a connector

OpenClaw, Hermes (and future Nano) live under `src/Domains/Connectors/`, but **`AgentRuntime` itself is a primary domain** at `src/Domains/Intelligence/AgentRuntime/`. The connector folders only hold per-runtime implementations of the shared `AgentRuntimeProvider` contract. If you see `app/GraphQL/Connector/AgentRuntime/`, `graphql/schemas/Connector/agentruntime.graphql`, `hermesLaunchAgent`, `openclawTerminateAgent`, or any per-runtime mutation, that's the wrong shape — delete it. The whole graph is `agentRuntime*` and routes by `agent_deployments.provider`.

- **Provider source of truth:** `agent.agentType.provider` pre-launch and `agent_deployments.provider` post-launch. There is **no `agents.agent_provider`** column.
- **Resolvers always go through `AgentRuntimeProviderFactory`** (`forAgent` / `forDeployment` / `forProvider`); never inject a DI container or instantiate a concrete provider. We tried a service-provider + registry once and deleted it.
- **Per-runtime variation belongs on `ProviderConfig`**, not in `Base*Action` bodies. New variation point (directory name, CLI alias, config filename, image name, custom-field key) → add a field to `ProviderConfig` and populate it in every connector's `SshClient::makeProviderConfig()`.
- **Shared per-agent credentials (Slack, Telegram tokens, etc.) live on the primary domain** under `AgentChannelTokenEnum` — one shared key per credential, NOT `OPENCLAW_SLACK_BOT_TOKEN` + `HERMES_SLACK_BOT_TOKEN`. Runtime-specific things (gateway tokens, deployment ids, workspace paths) DO belong on the per-connector `CustomFieldEnum`.
- **Cross-runtime migration is "target adopts source", not "source pushes to target".** `AgentRuntimeProvider::dispatchAdoptForeignDeployment` is implemented on the destination runtime. The single mutation is `agentRuntimeMigrateAgentToProvider` with a `target_provider` field. Don't add `hermesMigrateFromOpenclaw`.

### Never cache SDK instances in static properties (Octane footgun)

Do NOT cache external-SDK clients in `private static array $instances = []` keyed by `app_X` / `company_X`. Under Swoole/Octane the worker is long-lived, so static state survives across requests. When a tenant rotates credentials, workers that cached the old client keep serving requests with stale keys — intermittent 4xx/auth errors that hit *some* requests but not others.

```php
// WRONG — stale credentials per worker after key rotation
private static array $instances = [];
public static function getInstance(AppInterface $app): SomeSDK
{
    return self::$instances['app_' . $app->getId()] ??= new SomeSDK($app->get('api_key'));
}

// CORRECT — thin factory, always reads fresh creds
public static function getInstance(AppInterface $app): SomeSDK
{
    return new SomeSDK($app->get('api_key'));
}
```

Building an SDK client is cheap (string assignments, no network handshake). Same rule applies to any singleton-style `protected static ?SDK $instance = null` pattern and to **any mutable static state** on connector classes (e.g. `protected static string $environment` mutated via `setEnvironment()`).

If you genuinely need to cache something heavy, key on a credential fingerprint (`hash($sid.$token)`), not the app/company id, so rotation invalidates the cache automatically.

### Activities go in `Activities/`, flat — NOT `Workflows/Activities/`

New connectors put workflow activities in `src/Domains/Connectors/{ConnectorName}/Activities/`. The older nested `Workflows/Activities/` shape is deprecated.

### `executeIntegration` requires `additionalParams`

All calls to `$this->executeIntegration()` in workflow activities must include `additionalParams: $params`. Without it, the system cannot retry the activity with the correct parameters.

### Always seed the `integrations` row

When shipping a new connector, provide the SQL insert for the `integrations` table:

```sql
INSERT INTO integrations (name, uuid, apps_id, config, handler, type, actions_id, receivers_id, is_deleted, created_at, updated_at)
VALUES ('{name}', UUID(), 0, '{"api_key": {"type": "text", "required": true}}', 'Kanvas\\Connectors\\{Name}\\Handlers\\{Name}Handler', 'key', NULL, NULL, 0, NOW(), NOW());
```

`apps_id = 0` means global (available to all apps). Reference the `DriveCentric` row for format. `type` (`IntegrationTypeEnum`: `key` | `oauth` | `mcp`) is what the UIs filter on — see the `kanvas-connector` skill.

### Register workflow activities

Add `#[WorkflowAction]` (from `Kanvas\Workflow\Attributes\WorkflowAction`) on the class. The `kanvas:workflow-sync-actions` command (runs on deploy) auto-discovers every class that carries the attribute via `WorkflowActionDiscoveryService` — no manual registration needed. If the basename collides with another activity in a different connector, pass `#[WorkflowAction(name: 'Human Name')]` to disambiguate. A coverage test (`tests/Workflow/Integration/WorkflowActionCoverageTest.php`) fails if any `KanvasActivity` / `ProcessWebhookJob` subclass is missing the attribute.
