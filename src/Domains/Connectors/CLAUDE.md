# Connectors — Kanvas Ecosystem API

Loads when work touches `src/Domains/Connectors/`. For the full scaffold pattern (Handler + Client + DTO + Enums + Webhook + Workflow + GraphQL + `integrations` row), invoke the `kanvas-connector` skill.

Per-connector `CLAUDE.md` (load when working in that connector's tree):
- [`PiDev/CLAUDE.md`](PiDev/CLAUDE.md) — pi.dev coding-agent job runner: agent-scoped GitHub token/allow-list, 3-tier rules of engagement, Kanvas-owned job durability + poller, `Neuron/Tools/Coding/` tools.
- [`Intellicheck/CLAUDE.md`](Intellicheck/CLAUDE.md) — ID verification: the inbound base64 + `private_data.result` contract, the selfie in `facial.data.photoFace`, why a "folder" is a root message and the report must thread as a child, the `generate-id-verification` vs deprecated `after-id-verification` split, and the two DB rows without which firing a verb does nothing silently.
- [`WordPress/CLAUDE.md`](WordPress/CLAUDE.md) — publishing a Message as a wp/v2 post: the message body post structure + fallbacks, Application Password setup through the generic `integrationCompany` mutation, and why the scraper `Client` and the `RestClient` are unrelated.
- [`UniversalSeguros/CLAUDE.md`](UniversalSeguros/CLAUDE.md) — auto-insurance SDK + its `Providers/UniversalSegurosProvider` implementation of the `Kanvas\Insurance` contracts. Per-product emit scopes, QA chassis blocker, problem+json error shape.
- [`WaSender/CLAUDE.md`](WaSender/CLAUDE.md) — inbound WhatsApp: the three conversation shapes (lead DM / assistant DM / group) and how they route, the full `receiver_webhooks.configuration` key table, burst debouncing, which entity each workflow event carries (and why group traffic must never hit the DM event), and the lid-addressing + `slug`-vs-`uuid` foot-guns.
- [`Yusen/CLAUDE.md`](Yusen/CLAUDE.md) — 3PL Item Balance XML → discrepancy report: the exact POST Yusen makes (multipart vs raw body), why the connector writes no stock (a per-source warehouse double-counts `Variants::setTotalQuantity()`), the lot-summing assumption and its `multi_record_items` tripwire, and the synthetic-fixture rule.

## Hard rules specific to this tree

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
INSERT INTO integrations (name, uuid, apps_id, config, handler, actions_id, receivers_id, is_deleted, created_at, updated_at)
VALUES ('{name}', UUID(), 0, '{"api_key": {"type": "text", "required": true}}', 'Kanvas\\Connectors\\{Name}\\Handlers\\{Name}Handler', NULL, NULL, 0, NOW(), NOW());
```

`apps_id = 0` means global (available to all apps). Reference the `DriveCentric` row for format.

### Register workflow activities

Add `#[WorkflowAction]` (from `Kanvas\Workflow\Attributes\WorkflowAction`) on the class. The `kanvas:workflow-sync-actions` command (runs on deploy) auto-discovers every class that carries the attribute via `WorkflowActionDiscoveryService` — no manual registration needed. If the basename collides with another activity in a different connector, pass `#[WorkflowAction(name: 'Human Name')]` to disambiguate. A coverage test (`tests/Workflow/Integration/WorkflowActionCoverageTest.php`) fails if any `KanvasActivity` / `ProcessWebhookJob` subclass is missing the attribute.
