<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Amplitude's hosted server — analytics queries, charts, dashboards, cohorts, experiments, flags, taxonomy
 * and session replays. Two rows because EU-residency orgs live on a separate host whose accounts the US
 * server cannot see.
 *
 * Probed 2026-09-29: the 401 names `/.well-known/oauth-protected-resource` (root, not `/mcp`), the resource
 * is its own authorization server, and `/register` accepted `{app.url}/v1/oauth/callback` first try —
 * one click, no hand-made client. It downgrades the client to public (`none`) whatever is asked for.
 *
 * Scopes are sent explicitly: with no `oauth` block no scope parameter goes out, and `offline_access` is
 * advertised by the authorization server (not the resource) — it is what gets a refresh token.
 * `mcp:write` is still gated per user by Amplitude's own `USE_MCP_WRITE` RBAC action.
 */
return new class () extends Migration {
    private const array SERVERS = [
        'amplitude_mcp' => 'https://mcp.amplitude.com/mcp',
        'amplitude_eu_mcp' => 'https://mcp.eu.amplitude.com/mcp',
    ];

    protected $connection = 'workflow';

    public function up(): void
    {
        foreach (self::SERVERS as $name => $url) {
            $exists = DB::connection('workflow')->table('integrations')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::connection('workflow')->table('integrations')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'handler' => McpHandler::class,
                'type' => IntegrationTypeEnum::MCP->value,
                'apps_id' => 0,
                'config' => json_encode([]),
                'metadata' => json_encode([
                    'vendor' => 'Amplitude',
                    'transport' => 'http',
                    'url' => $url,
                    'auth_methods' => ['oauth'],
                    'prefix' => 'amplitude',
                    'exclude' => [],
                    'oauth' => [
                        'scopes' => [
                            'mcp:read',
                            'mcp:write',
                            'offline_access',
                        ],
                    ],
                    'timeout_ms' => 30000,
                    'url_per_connection' => false,
                ]),
                'is_deleted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->whereIn('name', array_keys(self::SERVERS))
            ->where('apps_id', 0)
            ->delete();
    }
};
