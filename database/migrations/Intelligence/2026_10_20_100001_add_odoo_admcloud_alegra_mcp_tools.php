<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

/**
 * `Odoo (MCP)` because grants resolve by label and the native Odoo connector already exists.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    private const array TOOLS = [
        'odoo_mcp' => [
            'title' => 'Odoo (MCP)',
            'description' => 'Odoo over MCP — search, read and group records in the company\'s own Odoo database '
                . '(contacts, sales, invoices, inventory, projects, any model the account can see), and create or '
                . 'update records when the Odoo admin has exposed those tools. Look up the model and its fields '
                . 'before querying, report only what the tools returned, and confirm with the person before '
                . 'creating or changing a record. Needs Odoo 20 or later: give the database URL ending in /mcp, '
                . 'then either sign in with OAuth (the Odoo admin must allow dynamic client registration under '
                . 'Settings → Integrations → MCP OAuth) or paste an Odoo API key created with the MCP scope.',
        ],
        'admcloud_mcp' => [
            'title' => 'ADM Cloud',
            'description' => 'ADM Cloud over MCP — the company\'s accounting and ERP: customers, vendors, items, '
                . 'invoices, quotes, sales and purchase orders, payments, bills, credit memos, bank balances, '
                . 'receivables, payables and financial statements. Call get_current_context first and, if no company '
                . 'is active, list_companies then select_company. Creating an invoice, payment, bill or credit memo '
                . 'posts to the books — confirm the amounts with the person first. Deleting is not available. '
                . 'Report only figures the tools returned. Connect it with the Connect button — it signs in with '
                . 'your ADM Cloud account.',
        ],
        'alegra_mcp' => [
            'title' => 'Alegra',
            'description' => 'Alegra over MCP — read-only access to the connected Alegra account: products and '
                . 'inventory, warehouses, price lists, contacts, invoices and income, expenses, banks and settings. '
                . 'It cannot create, change or delete anything, so say so rather than promising a change. Report '
                . 'only what the tools returned. Connect it with the Connect button — an Alegra administrator signs '
                . 'in with their Alegra account.',
        ],
    ];

    public function up(): void
    {
        foreach (self::TOOLS as $slug => $tool) {
            $integrationId = DB::connection('workflow')->table('integrations')
                ->where('name', $slug)
                ->where('apps_id', 0)
                ->value('id');

            $exists = DB::connection('intelligence')->table('nervous_system_tools')
                ->where('name', $tool['title'])
                ->where('apps_id', 0)
                ->exists();

            if ($integrationId === null || $exists) {
                continue;
            }

            DB::connection('intelligence')->table('nervous_system_tools')->insert([
                'uuid' => (string) Str::uuid(),
                'apps_id' => 0,
                'name' => $tool['title'],
                'description' => $tool['description'],
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
            ->whereIn('name', array_column(self::TOOLS, 'title'))
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
