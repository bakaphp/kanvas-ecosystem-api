<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for Deel, Klaviyo and HubSpot — `neuron` only (the Claude Managed Agents
 * bridge does not carry MCP rows yet).
 *
 * Each description states what the agent may and may not do: Deel is granted read-only here, while
 * HubSpot and Klaviyo can change records, and an agent that mistakes one for the other either refuses
 * work it could do or promises work it cannot.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    /** Integration slug => [catalog title, description]. */
    private const array TOOLS = [
        'deel_mcp' => [
            'Deel',
            'Deel over MCP — READ ONLY visibility of the connected Deel account: people and workers, contracts, organizations, time off, timesheets and payment statements. It is granted no write scopes, so it cannot start a payment, change a contract or approve anything — say so plainly rather than promising it.',
        ],
        'klaviyo_mcp' => [
            'Klaviyo',
            'Klaviyo over MCP — email and SMS marketing on the connected Klaviyo account: profiles and lists, segments, campaigns, flows and their performance. It can change marketing records, so confirm anything that would send to real subscribers before doing it.',
        ],
        'hubspot_mcp' => [
            'HubSpot',
            'HubSpot over MCP — the connected HubSpot account\'s CRM: contacts, companies, deals, tickets, products, orders, invoices and quotes, plus engagements like calls, emails, meetings, notes and tasks. It can WRITE as well as read, and acts with the permissions of whoever signed in.',
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
