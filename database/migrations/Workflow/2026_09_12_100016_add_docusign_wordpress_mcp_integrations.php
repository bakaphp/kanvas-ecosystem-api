<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * DocuSign hosts one server at a fixed address; WordPress hosts none at all.
 *
 * DocuSign discovers its endpoints through `account.docusign.com` (PKCE S256) but does NO dynamic client
 * registration, so — like Google and GitHub — an admin creates one OAuth app and stores it as the app
 * settings `mcp_oauth_client_id_docusign` / `mcp_oauth_client_secret_docusign`, shared via `client_key`.
 * Until that exists, Connect answers with a validation error naming the missing setting. Production
 * access also requires enrolment in DocuSign's MCP beta. The scopes are the three its RFC 9728 metadata
 * advertises.
 *
 * WordPress's server is the MCP Adapter plugin running on the company's OWN site (the successor to
 * Automattic's archived plugin), which serves `/wp-json/mcp/mcp-adapter-default-server`. So it takes an
 * address per connection, like n8n. Kanvas already has a native WordPress connector that publishes with
 * an Application Password; this is the per-agent alternative.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'docusign_mcp' => [
            'vendor' => 'DocuSign',
            'prefix' => 'docusign',
            'auth_methods' => ['oauth'],
            'url' => 'https://mcp.docusign.com/mcp',
            'oauth' => [
                'client_key' => 'docusign',
                'scopes' => ['signature', 'aow_manage', 'adm_store_unified_repo_read'],
            ],
        ],
        'wordpress_mcp' => [
            'vendor' => 'WordPress',
            'prefix' => 'wordpress',
            'auth_methods' => ['bearer', 'oauth'],
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
                'timeout_ms' => 20000,
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
