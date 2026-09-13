<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Pipeboard brokers the ad platforms whose own servers we cannot use: Meta refuses client registration to
 * anyone it has not approved, and Google publishes no hosted Ads server at all. Pipeboard registers
 * clients dynamically (`pipeboard.co/oauth/register`, PKCE S256), so both connect in one click.
 *
 * The trade is where the credentials live: the advertiser signs in to Pipeboard, and Pipeboard — not
 * Kanvas — holds the platform tokens. That is a vendor-risk decision, which is why the catalog rows name
 * the broker rather than pretending to be the platform.
 *
 * Scopes differ on purpose. Meta gets `mcp:write` because acting on campaigns is the whole reason to go
 * through a broker there. Google Ads is read-only, matching what Google's own server offers, so an agent
 * cannot move live bids by default — widen it deliberately, not by accident.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'pipeboard_meta_ads_mcp' => [
            'vendor' => 'Meta Ads (Pipeboard)',
            'prefix' => 'pbmeta',
            'url' => 'https://mcp.pipeboard.co/meta-ads-mcp',
            'scopes' => ['mcp:read', 'mcp:write'],
        ],
        'pipeboard_google_ads_mcp' => [
            'vendor' => 'Google Ads (Pipeboard)',
            'prefix' => 'pbgoogle',
            'url' => 'https://mcp.pipeboard.co/google-ads-mcp',
            'scopes' => ['mcp:read'],
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
                        'scopes' => $server['scopes'],
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
