<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for Microsoft's MCP servers — `neuron` only (the Claude Managed Agents
 * bridge does not carry MCP rows yet). Named after the vendor, like every other MCP row.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    /** Integration slug => [catalog title, description]. */
    private const array TOOLS = [
        'ms_learn_mcp' => [
            'Microsoft Learn',
            'Microsoft Learn over MCP — searching and fetching official Microsoft and Azure documentation, so an agent answers from first-party docs instead of memory. Public: it needs no account and no credential.',
        ],
        'microsoft365_mcp' => [
            'Microsoft 365',
            'Microsoft 365 over MCP, through your tenant\'s Agent 365 tooling gateway — Outlook mail and calendar, SharePoint, OneDrive, Word, Dataverse and search, acting under the permissions of whoever signs in. Connect it with the server address your tenant shows (`.../tenants/{tenantId}/servers/mcp_...`); it requires Agent 365 and Microsoft 365 Copilot licensing. Kanvas also ships a native Microsoft connector for Outlook mail and calendar — grant that OR this, not both.',
        ],
        'teams_mcp' => [
            'Microsoft Teams',
            'Microsoft Teams over MCP — reading and posting messages, and working with chats and channels, through your tenant\'s Agent 365 Teams server. Connect it with that address (`.../tenants/{tenantId}/servers/mcp_TeamsServer`); it requires Agent 365 and Microsoft 365 Copilot licensing, and the agent can do whatever the signed-in account may do.',
        ],
    ];

    public function up(): void
    {
        foreach (self::TOOLS as $slug => [$title, $description]) {
            $integrationId = DB::connection('workflow')->table('integrations')
                ->where('name', $slug)
                ->where('apps_id', 0)
                ->value('id');

            $exists = DB::connection('intelligence')->table('nervous_system_tools')
                ->where('name', $title)
                ->where('apps_id', 0)
                ->exists();

            if ($integrationId === null || $exists) {
                continue;
            }

            DB::connection('intelligence')->table('nervous_system_tools')->insert([
                'uuid' => (string) Str::uuid(),
                'apps_id' => 0,
                'name' => $title,
                'description' => $description,
                'tool_type' => ToolTypeEnum::MCP->value,
                'handler' => null,
                'integrations_id' => $integrationId,
                'frameworks' => json_encode(['neuron']),
                'version' => '1.0.0',
                'is_active' => 1,
                'is_deleted' => 0,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('intelligence')->table('nervous_system_tools')
            ->whereIn('name', array_column(self::TOOLS, 0))
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
