<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * TikTok for Business, connected in one click: its authorization server registers clients dynamically
 * (`.../oauth/register`, PKCE S256, refresh tokens), so no developer credential is needed.
 *
 * TikTok publishes the same server twice. `tt-ads-mcp-flat` exposes all ~400 tools at once;
 * `tt-ads-mcp-layer` discloses them progressively, which is what this row points at — a toolset that size
 * in one prompt is precisely what made Gemini reject entire turns here before.
 *
 * Discovery needs no override despite an unusual shape: the issuer is `{server}/oauth` and its metadata
 * sits at `{issuer}/.well-known/openid-configuration` (the RFC 8414 path-inserted URL 404s), which is the
 * third candidate McpOAuthDiscoveryService already tries.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'tiktok_ads_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'tiktok_ads_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'TikTok Ads',
                'url' => 'https://business-api.tiktok.com/open_mcp/tt-ads-mcp-layer',
                'transport' => 'http',
                'auth_methods' => ['oauth'],
                'prefix' => 'tiktok',
                'exclude' => [],
                'timeout_ms' => 30000,
                'oauth' => [
                    'scopes' => ['mcp:tt4b'],
                ],
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'tiktok_ads_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
