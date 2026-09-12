<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * The grantable catalog rows for the enterprise MCP servers — `neuron` only (the Claude Managed Agents
 * bridge does not carry MCP rows yet). Named after the vendor, like every other MCP row.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    /** Integration slug => [catalog title, description]. */
    private const array TOOLS = [
        'mssql_mcp' => [
            'Microsoft SQL Server',
            'Microsoft SQL Server over MCP — querying and exploring a SQL Server database through the SQL MCP Server your company runs (part of Data API Builder). Connect it with that server\'s public https address and, where it is protected, its token. Whatever the database user can read, the agent can read.',
        ],
        'salesforce_mcp' => [
            'Salesforce',
            'Salesforce over MCP — reading and updating CRM records through your org\'s own hosted MCP server (Enterprise Edition and above). Connect it with your org\'s server address and sign in; the agent acts strictly within that user\'s Salesforce permissions.',
        ],
        'sap_btp_mcp' => [
            'SAP',
            'SAP over MCP — querying and administering an SAP BTP landscape through the remote MCP endpoint SAP provides for it: entitlements, subaccounts, user assignments and provisioning. Connect it with your landscape\'s address and its credential.',
        ],
        'shopify_mcp' => [
            'Shopify',
            'Shopify over MCP — the STOREFRONT surface of one shop: searching the catalog, reading policies and FAQs, and building a cart. This is what a shopper can see, not an admin API: it cannot read orders or customers. Connect it with the shop\'s address, `https://{shop}.myshopify.com/api/mcp`.',
        ],
        'bigcommerce_mcp' => [
            'BigCommerce',
            'BigCommerce over MCP — storefront commerce tools for one store, through the MCP URL its control panel issues (beta). Connect it with that URL and the store\'s token.',
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
