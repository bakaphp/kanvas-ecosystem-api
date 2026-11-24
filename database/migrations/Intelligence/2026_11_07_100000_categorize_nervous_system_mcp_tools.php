<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;

return new class () extends Migration {
    protected $connection = 'intelligence';

    private const string FALLBACK = 'other';

    private const array NEW_CATEGORIES = [
        'finance-billing' => [
            'name' => 'Finance & Billing',
            'description' => 'Accounting, payments, billing and financial data.',
            'display_order' => 130,
        ],
        'marketing-analytics' => [
            'name' => 'Marketing & Analytics',
            'description' => 'Marketing platforms, advertising and analytics.',
            'display_order' => 140,
        ],
    ];

    private const array CATEGORY_MATCHES = [
        'finance-billing' => ['stripe', 'mercury', 'quickbooks', 'alegra', 'adm cloud', 'accounting', 'sap', 'odoo', 'deel'],
        'marketing-analytics' => ['ads', 'analytics', 'amplitude', 'klaviyo', 'tiktok', 'trends', 'keyword planner', 'ad library', 'higgsfield', 'hyperframes', 'pipeboard', 'scrapecreators'],
        'commerce' => ['shopify', 'bigcommerce', 'woocommerce', 'e-commerce', 'storefront'],
        'crm' => ['hubspot', 'salesforce', 'pipedrive', 'zoho', 'apollo', 'google people', 'crm'],
        'communication' => ['slack', 'teams', 'whatsapp', 'mailgun', 'gmail', 'outlook', 'google workspace', 'microsoft 365'],
        'calendar' => ['calendar', 'granola'],
        'knowledge' => ['notion', 'drive', 'sheets', 'docusign', 'wordpress', 'jotform', 'learn', 'tavily', 'figma'],
        'workflow' => ['n8n', 'kias'],
        'code' => ['github', 'linear', 'atlassian', 'sentry', 'vercel', 'typesense', 'browser', 'kernel', 'playwright', 'developer tools', 'sql server', 'salesassist'],
    ];

    public function up(): void
    {
        $connection = DB::connection('intelligence');
        $now = now();

        foreach (self::NEW_CATEGORIES as $slug => $category) {
            $exists = $connection->table('nervous_system_tool_categories')
                ->where('apps_id', 0)
                ->where('slug', $slug)
                ->exists();

            if ($exists) {
                continue;
            }

            $connection->table('nervous_system_tool_categories')->insert([
                ...$category,
                'apps_id' => 0,
                'slug' => $slug,
                'is_active' => true,
                'is_deleted' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $categoryIds = $connection->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->whereIn('slug', [...array_keys(self::CATEGORY_MATCHES), self::FALLBACK])
            ->pluck('id', 'slug');

        $tools = $connection->table('nervous_system_tools')
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->whereNull('tool_category_id')
            ->get(['id', 'name']);

        foreach ($tools as $tool) {
            $categoryId = $categoryIds[$this->categorySlugFor($tool->name)] ?? $categoryIds[self::FALLBACK];

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
        foreach (self::CATEGORY_MATCHES as $slug => $terms) {
            foreach ($terms as $term) {
                // Anchored to a word start: a bare substring files "WhatsApp" under `sap` and "Leads" under `ads`.
                if (preg_match('/\b' . preg_quote($term, '/') . '/iu', $toolName) === 1) {
                    return $slug;
                }
            }
        }

        return self::FALLBACK;
    }
};
