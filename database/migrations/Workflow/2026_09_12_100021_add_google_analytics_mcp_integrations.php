<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Google Analytics ships TWO hosted MCP servers, one per API, and its developer docs mention neither —
 * they only point at a GitHub install. Both were confirmed live: they answer `initialize` and
 * `tools/list`, identify as Google's `StatelessServer`, and every tool they publish is annotated
 * `readOnlyHint`, matching the documented "read requests only".
 *
 * The paths differ by host and neither is guessable: the data API answers on `/mcp/v1` (its own metadata
 * names `/mcp`, which 404s), the admin API on `/mcp`.
 *
 * They authenticate against accounts.google.com like the Workspace servers, so they share the same
 * hand-made Google client through `client_key` — an app that already connected Gmail or Sheets needs no
 * further setup. Scope is the read-only one; `analytics` (write) is deliberately not requested, since
 * these servers cannot write anyway.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'google_analytics_mcp' => [
            'vendor' => 'Google Analytics',
            'prefix' => 'ga4',
            'url' => 'https://analyticsdata.googleapis.com/mcp/v1',
        ],
        'google_analytics_admin_mcp' => [
            'vendor' => 'Google Analytics Admin',
            'prefix' => 'ga4admin',
            'url' => 'https://analyticsadmin.googleapis.com/mcp',
        ],
    ];

    public function up(): void
    {
        foreach (self::SERVERS as $name => $server) {
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
                    'vendor' => $server['vendor'],
                    'url' => $server['url'],
                    'transport' => 'http',
                    'auth_methods' => ['oauth'],
                    'prefix' => $server['prefix'],
                    'exclude' => [],
                    'timeout_ms' => 30000,
                    'oauth' => [
                        'client_key' => 'google',
                        'scopes' => ['https://www.googleapis.com/auth/analytics.readonly'],
                        'authorize_params' => [
                            'access_type' => 'offline',
                            'prompt' => 'consent',
                        ],
                    ],
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
