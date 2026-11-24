<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Google Keyword Planner through Apify's hosted MCP server, running the `smacient/google-keyword-planner-mcp`
 * Actor. Google's own Ads MCP server is still self-host only (see the Pipeboard row), and the open-source
 * planner servers (ncosentino/google-keyword-planner-mcp, the Ryze binary) are local processes that need the
 * company's Google Ads OAuth client and refresh token on the box — not something a tenant pastes into a
 * connect dialog. The Actor holds its own Google Ads access and charges per call (from $0.045 per keyword-ideas
 * call, 2026-10-24), so the person needs only an Apify account.
 *
 * Why Apify's front door and not the Actor's other two addresses (probed 2026-10-24):
 *  - `apify-gkpmcp.smacient.com/mcp` (the vendor's own hosted connector, a Cloud Run backend) is OAuth-only,
 *    publishes no `refresh_token` grant, and its `/register` refused every payload (`client_registration_failed`),
 *    so every agent would need a hand-made client and reconnect at each expiry.
 *  - `smacient--google-keyword-planner-mcp.apify.actor/mcp` (Standby) answers 401 `api-token-missing` to
 *    everything without an Apify token, so whether its `/mcp` speaks MCP is unverified. Re-probe with a token;
 *    if it does, it would expose the planner's tools directly and synchronously, and could replace this URL.
 *
 * `mcp.apify.com` is the clean kind: `initialize` → 401 naming `console-backend.apify.com` as the authorization
 * server (origin-level protected-resource document; the path-aware one answers the same), RFC 8414 at that
 * origin's root, PKCE S256, public clients (`none`), `refresh_token` grant, one scope (`full_api_access`), and
 * `/oauth/apps` took `{app.url}/v1/oauth/callback` first try — one click. The token step refused a bogus
 * code before looking at `resource`, so whether Apify validates the resource against the bare origin while
 * we send this query-string URL is unknown until the first real consent: a token-step `invalid_target` means
 * `include_resource: false` (HeyGen precedent), not a broken row. The pasted-key method is a plain Apify API
 * token as `Authorization: Bearer`.
 *
 * `?tools=<actor>` exposes that Actor as one tool (expected name `smacient--google-keyword-planner-mcp`) and
 * drops Apify's defaults (Actor search, docs, the RAG browser); Apify then auto-adds `get-actor-run`,
 * `get-dataset-items`, `get-key-value-store-record` and `abort-actor-run`. The Actor tool waits up to its
 * `waitSecs` (default 30, max 45) and answers with run metadata, storage ids and a `nextStep`, never the
 * keyword rows themselves — those come from `get-dataset-items` with the returned `datasetId`. Planner calls
 * finish in seconds, so the run is normally SUCCEEDED inside the wait and the agent makes two calls, which the
 * catalog description spells out. Not declared under `async_jobs` on purpose: `tools/list` needs an Apify
 * token and the result's id/status field names are unconfirmed; declare it from the first real listing if runs
 * turn out to outlive the wait. The 60 s timeout covers the wait plus Apify's own overhead.
 *
 * Tool identifiers on the Actor side are unprobed (the page shows `get_keyword_ideas`, historical metrics and
 * month-over-month analysis plus an API-only `list_available_locations`). Nothing is excluded: every tool here
 * is a read, and `abort-actor-run` only stops the agent's own run.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'google_keyword_planner_mcp';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => self::NAME,
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Google Keyword Planner (Apify)',
                'transport' => 'http',
                'url' => 'https://mcp.apify.com/?tools=smacient/google-keyword-planner-mcp',
                'auth_methods' => [
                    'oauth',
                    'bearer',
                ],
                'oauth' => [
                    'scopes' => ['full_api_access'],
                ],
                'prefix' => 'gkp',
                'exclude' => [],
                'timeout_ms' => 60000,
                'url_per_connection' => false,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('type', IntegrationTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
