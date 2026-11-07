<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    protected $connection = 'intelligence';

    private const array CATEGORIES = [
        'crm' => ['name' => 'CRM', 'display_order' => 10, 'description' => 'Leads, contacts, pipeline stages.'],
        'commerce' => ['name' => 'Commerce', 'display_order' => 30, 'description' => 'Orders, checkout, fulfillment.'],
        'workflow' => ['name' => 'Workflow', 'display_order' => 50, 'description' => 'Rules and automations the agent fires.'],
        'communication' => ['name' => 'Communication', 'display_order' => 90, 'description' => 'Email, voice, push, transactional messaging.'],
        'calendar' => ['name' => 'Calendar', 'display_order' => 100, 'description' => 'Meetings, scheduling, availability.'],
        'knowledge' => ['name' => 'Knowledge', 'display_order' => 110, 'description' => 'Knowledge base, docs, web search.'],
        'code' => ['name' => 'Code', 'display_order' => 120, 'description' => 'Sandboxed scripts and developer-defined tools.'],
        'finance-billing' => ['name' => 'Finance & Billing', 'display_order' => 130, 'description' => 'Accounting, payments, billing and financial data.'],
        'marketing-analytics' => ['name' => 'Marketing & Analytics', 'display_order' => 140, 'description' => 'Marketing platforms, advertising and analytics.'],
        'other' => ['name' => 'Other', 'display_order' => 999, 'description' => 'Custom or uncategorized tools.'],
    ];

    private const array CATEGORY_MATCHES = [
        'finance-billing' => ['stripe', 'mercury', 'quickbooks', 'alegra', 'adm cloud', 'accounting', 'sap', 'odoo', 'deel'],
        'marketing-analytics' => ['ads', 'analytics', 'amplitude', 'klaviyo', 'tiktok', 'trends', 'keyword planner', 'ad library', 'higgsfield', 'hyperframes', 'pipeboard', 'scrapecreators'],
        'commerce' => ['shopify', 'bigcommerce', 'woocommerce', 'e-commerce', 'storefront'],
        'crm' => ['hubspot', 'salesforce', 'pipedrive', 'zoho', 'apollo', 'google people', 'crm'],
        'communication' => ['slack', 'teams', 'whatsapp', 'mailgun', 'gmail', 'outlook', 'google workspace'],
        'calendar' => ['calendar', 'granola'],
        'knowledge' => ['notion', 'drive', 'sheets', 'docusign', 'wordpress', 'jotform', 'learn', 'tavily', 'figma'],
        'workflow' => ['n8n', 'kias'],
        'code' => ['github', 'linear', 'atlassian', 'sentry', 'vercel', 'typesense', 'browser', 'kernel', 'playwright', 'developer tools', 'sql server', 'salesassist'],
    ];

    public function up(): void
    {
        $connection = DB::connection('intelligence');
        $now = now();

        foreach (self::CATEGORIES as $slug => $category) {
            $values = [
                'name' => $category['name'],
                'description' => $category['description'],
                'display_order' => $category['display_order'],
                'is_active' => true,
                'is_deleted' => false,
                'updated_at' => $now,
            ];
            $existing = $connection->table('nervous_system_tool_categories')
                ->where('apps_id', 0)
                ->where('slug', $slug)
                ->exists();

            if ($existing) {
                $connection->table('nervous_system_tool_categories')
                    ->where('apps_id', 0)
                    ->where('slug', $slug)
                    ->update($values);
                continue;
            }

            $connection->table('nervous_system_tool_categories')->insert([
                ...$values,
                'apps_id' => 0,
                'slug' => $slug,
                'created_at' => $now,
            ]);
        }

        $categoryIds = $connection->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->whereIn('slug', array_keys(self::CATEGORIES))
            ->pluck('id', 'slug');

        $tools = $connection->table('nervous_system_tools')
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->whereNull('tool_category_id')
            ->get(['id', 'name']);

        foreach ($tools as $tool) {
            $categoryId = $categoryIds[$this->categorySlugFor($tool->name)] ?? $categoryIds['other'];

            $connection->table('nervous_system_tools')
                ->where('id', $tool->id)
                ->whereNull('tool_category_id')
                ->update(['tool_category_id' => $categoryId]);
        }
    }

    public function down(): void
    {
    }

    private function categorySlugFor(string $toolName): string
    {
        $toolName = mb_strtolower($toolName);

        foreach (self::CATEGORY_MATCHES as $slug => $terms) {
            foreach ($terms as $term) {
                if (str_contains($toolName, $term)) {
                    return $slug;
                }
            }
        }

        return 'other';
    }
};
