<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * The two ad platforms, which could hardly be more different to connect.
 *
 * Meta hosts its own server at a fixed address and registers clients dynamically (its metadata, reached
 * at `https://www.facebook.com/.well-known/oauth-authorization-server/ads`, advertises a
 * `registration_endpoint`, PKCE S256 and refresh tokens) — so an admin just clicks Connect, with no
 * developer app and no app review. The scopes are the seven its protected-resource metadata lists;
 * `ads_management` is the write half, and Meta lands every campaign, ad set and ad it creates in PAUSED.
 *
 * Google publishes NO hosted server for Ads. The official one is Python over stdio, read-only, and needs
 * a developer token; the documented remote path is deploying it to Cloud Run yourself, so this row takes
 * an address per connection and a bearer token when that deployment is protected.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'meta_ads_mcp' => [
            'vendor' => 'Meta Ads',
            'prefix' => 'metaads',
            'auth_methods' => ['oauth'],
            'url' => 'https://mcp.facebook.com/ads',
            'oauth' => [
                'scopes' => [
                    'ads_management',
                    'ads_read',
                    'catalog_management',
                    'business_management',
                    'pages_show_list',
                    'instagram_basic',
                    'ads_mcp_management',
                ],
            ],
        ],
        'google_ads_mcp' => [
            'vendor' => 'Google Ads',
            'prefix' => 'googleads',
            'auth_methods' => ['bearer', 'none'],
            'url' => null,
            'oauth' => null,
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

            $metadata = [
                'vendor' => $server['vendor'],
                'transport' => 'http',
                'auth_methods' => $server['auth_methods'],
                'prefix' => $server['prefix'],
                'exclude' => [],
                'timeout_ms' => 30000,
            ];

            if ($server['url'] === null) {
                $metadata['url_per_connection'] = true;
            } else {
                $metadata['url'] = $server['url'];
            }

            if ($server['oauth'] !== null) {
                $metadata['oauth'] = $server['oauth'];
            }

            DB::connection('workflow')->table('integrations')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'handler' => McpHandler::class,
                'type' => IntegrationTypeEnum::MCP->value,
                'apps_id' => 0,
                'config' => json_encode([]),
                'metadata' => json_encode($metadata),
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
