<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Three first-party servers, two of which connect in one click.
 *
 * Deel and Klaviyo both register clients dynamically (PKCE S256), so an admin just presses Connect.
 * HubSpot does not — its metadata offers no registration endpoint and its docs tell you to create a
 * user-level app yourself — so it takes the Google/GitHub/DocuSign treatment: one hand-made client stored
 * as `mcp_oauth_client_id_hubspot` / `mcp_oauth_client_secret_hubspot`, shared through `client_key`.
 *
 * Scope choices:
 *  - Deel publishes ~90 scopes covering payroll, payments and contracts, read and write. Only reads are
 *    requested: an HR agent that can move money by default is not a safe default. Widen deliberately.
 *  - Klaviyo advertises no scopes at all, so none are sent — a scope a resource never asked for is how a
 *    consent screen starts refusing.
 *  - HubSpot's resource advertises an empty scope list too; its app's own configuration decides access.
 *
 * HubSpot's address is `/anthropic`; `mcp.hubspot.com/mcp` 404s.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'deel_mcp' => [
            'vendor' => 'Deel',
            'prefix' => 'deel',
            'url' => 'https://api.letsdeel.com/mcp',
            'timeout_ms' => 30000,
            'oauth' => [
                'scopes' => [
                    'people:read',
                    'contracts:read',
                    'organizations:read',
                    'time-off:read',
                    'timesheets:read',
                    'payments:read',
                    'payment-statements:read',
                ],
            ],
        ],
        'klaviyo_mcp' => [
            'vendor' => 'Klaviyo',
            'prefix' => 'klaviyo',
            'url' => 'https://mcp.klaviyo.com/mcp',
            'timeout_ms' => 30000,
            'oauth' => null,
        ],
        'hubspot_mcp' => [
            'vendor' => 'HubSpot',
            'prefix' => 'hubspot',
            'url' => 'https://mcp.hubspot.com/anthropic',
            'timeout_ms' => 30000,
            'oauth' => ['client_key' => 'hubspot'],
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
                'url' => $server['url'],
                'transport' => 'http',
                'auth_methods' => ['oauth'],
                'prefix' => $server['prefix'],
                'exclude' => [],
                'timeout_ms' => $server['timeout_ms'],
            ];

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
