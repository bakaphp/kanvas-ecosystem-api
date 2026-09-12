<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Microsoft's MCP surface, which is not shaped like Google's.
 *
 *  - Microsoft Learn is one public server at a fixed address, with no credential at all: documentation
 *    search and fetch, useful to any agent that writes against Microsoft or Azure.
 *  - Everything else lives behind Agent 365's tooling gateway, one server per workload, at
 *    `https://agent365.svc.cloud.microsoft/agents/tenants/{tenantId}/servers/mcp_{Name}` — per tenant,
 *    behind Entra, and licensed (Agent 365 + Microsoft 365 Copilot). The workload is chosen by the URL
 *    segment (`mcp_TeamsServer`, `mcp_MailTools`, Calendar, SharePoint, Word, Dataverse, Me, …), which
 *    Microsoft's own sources spell inconsistently — so the admin pastes the exact address their tenant
 *    shows rather than us guessing it into a row. Teams gets its own row because it is the workload most
 *    people come looking for; both rows accept any Agent 365 server URL.
 *
 * Kanvas already has a native Microsoft connector for Outlook mail and calendar; these are the per-agent
 * alternative, and an agent should hold one or the other.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'ms_learn_mcp' => [
            'vendor' => 'Microsoft Learn',
            'prefix' => 'mslearn',
            'auth_methods' => ['none'],
            'url' => 'https://learn.microsoft.com/api/mcp',
        ],
        'microsoft365_mcp' => [
            'vendor' => 'Microsoft 365',
            'prefix' => 'ms365',
            'auth_methods' => ['oauth', 'bearer'],
            'url' => null,
        ],
        'teams_mcp' => [
            'vendor' => 'Microsoft Teams',
            'prefix' => 'teams',
            'auth_methods' => ['oauth', 'bearer'],
            'url' => null,
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
