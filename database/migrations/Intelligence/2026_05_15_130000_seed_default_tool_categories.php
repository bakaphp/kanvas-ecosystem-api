<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    protected $connection = 'intelligence';

    /**
     * Seeded categories — apps_id=0 makes them visible to every app.
     * The first batch mirrors KanvasModuleEnum so a tool's category aligns
     * with the module it talks to. Extras cover groupings the dashboard UI
     * already uses (calendar, knowledge, code) and a fallback "other".
     */
    private array $categories = [
        // Aligned with Kanvas modules
        ['slug' => 'crm',          'name' => 'CRM',           'description' => 'Leads, contacts, pipeline stages.',                    'icon' => 'users',         'display_order' => 10],
        ['slug' => 'inventory',    'name' => 'Inventory',     'description' => 'Products, stock levels, warehouses.',                  'icon' => 'box',           'display_order' => 20],
        ['slug' => 'commerce',     'name' => 'Commerce',      'description' => 'Orders, checkout, fulfillment.',                       'icon' => 'cart',          'display_order' => 30],
        ['slug' => 'social',       'name' => 'Social',        'description' => 'Channels: WhatsApp, web chat, email, SMS.',            'icon' => 'broadcast',     'display_order' => 40],
        ['slug' => 'workflow',     'name' => 'Workflow',      'description' => 'Rules and automations the agent fires.',                'icon' => 'flow',          'display_order' => 50],
        ['slug' => 'ai',           'name' => 'AI',            'description' => 'Models, prompts, embeddings, reasoning aids.',          'icon' => 'sparkles',      'display_order' => 60],
        ['slug' => 'action-engine', 'name' => 'Action Engine', 'description' => 'Where the agent dispatches jobs and triggers integrations.', 'icon' => 'lightning',     'display_order' => 70],
        ['slug' => 'ecosystem',    'name' => 'Ecosystem',     'description' => 'Platform foundation — auth, identity, multi-tenancy.', 'icon' => 'shield',        'display_order' => 80],

        // Dashboard-derived extras
        ['slug' => 'communication', 'name' => 'Communication', 'description' => 'Email, voice, push, transactional messaging.',         'icon' => 'envelope',      'display_order' => 90],
        ['slug' => 'calendar',     'name' => 'Calendar',      'description' => 'Meetings, scheduling, availability.',                  'icon' => 'calendar',      'display_order' => 100],
        ['slug' => 'knowledge',    'name' => 'Knowledge',     'description' => 'Knowledge base, docs, web search.',                    'icon' => 'book',          'display_order' => 110],
        ['slug' => 'code',         'name' => 'Code',          'description' => 'Sandboxed scripts and developer-defined tools.',        'icon' => 'terminal',      'display_order' => 120],

        // Fallback
        ['slug' => 'other',        'name' => 'Other',         'description' => 'Custom or uncategorized tools.',                       'icon' => 'tag',           'display_order' => 999],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->categories as $category) {
            $exists = DB::connection('intelligence')
                ->table('nervous_system_tool_categories')
                ->where('apps_id', 0)
                ->where('slug', $category['slug'])
                ->exists();
            if ($exists) {
                continue;
            }
            DB::connection('intelligence')->table('nervous_system_tool_categories')->insert([
                'uuid' => \Illuminate\Support\Str::uuid()->toString(),
                'apps_id' => 0,
                'slug' => $category['slug'],
                'name' => $category['name'],
                'description' => $category['description'],
                'icon' => $category['icon'],
                'display_order' => $category['display_order'],
                'is_active' => true,
                'is_deleted' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('intelligence')
            ->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->whereIn('slug', array_column($this->categories, 'slug'))
            ->delete();
    }
};
