<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Google Trends through HasData's hosted server. Google publishes no Trends API and no MCP server; the
 * community servers (andrewlwn77/google-trends-mcp and friends) are stdio processes over RapidAPI
 * scrapers, so a hosted scraping vendor is the only remote option. HasData's server exposes all 68 of
 * its APIs as tools; the `?apis=google_trends` query limits this row to the one tool the catalog title
 * promises (`hasdata_google_trends_search_getTrendsData`). The rest of HasData (SERP, Maps, Amazon,
 * Zillow, …) is a separate decision, not a wider `apis=` on this row.
 *
 * Probed 2026-10-24: `initialize` and `tools/list` answer WITHOUT a credential (200, SSE framed) — a tool
 * call is what needs one, so a wrong key is first refused at the first search, not at connect. The
 * origin-level protected-resource document (the path-aware one answers the same) names `mcp.hasdata.com`
 * as its own authorization server, RFC 8414 at the origin root: PKCE S256, public clients (`none`),
 * `refresh_token` grant, scopes `mcp:tools` + `offline_access`, and `/register` took
 * `{app.url}/v1/oauth/callback` first try — one click, no hand-made client. `/authorize` validates both
 * scope (`bogus` → `invalid_scope`) and resource (`invalid_target` names the two accepted prefixes,
 * `/mcp` and `/api/mcp`) and it ACCEPTED this row's full query-string URL as the resource, so
 * `include_resource` stays on. Each OAuth connection becomes an API key in the person's HasData
 * workspace, named after the client.
 *
 * The pasted-key method is HasData's `x-api-key` header; an OAuth token still travels as
 * `Authorization: Bearer` (Apollo precedent). Every successful call costs 10 HasData credits from the
 * connected workspace's plan, which is why the catalog description tells the agent to batch queries
 * (`timeseries` and `geoMap` take several comma-separated terms in one call) rather than loop.
 *
 * One synchronous tool, nothing to declare under `async_jobs`.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'google_trends_mcp';

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
                'vendor' => 'Google Trends (HasData)',
                'transport' => 'http',
                'url' => 'https://mcp.hasdata.com/api/mcp?apis=google_trends',
                'auth_methods' => [
                    'oauth',
                    'bearer',
                ],
                'auth_header' => 'x-api-key',
                'oauth' => [
                    'scopes' => [
                        'mcp:tools',
                        'offline_access',
                    ],
                ],
                'prefix' => 'gtrends',
                'exclude' => [],
                'timeout_ms' => 30000,
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
