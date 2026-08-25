<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers Yusen Logistics against the generic integration mechanism. Setup runs through the
 * shared `integrationCompany` mutation, which reads `handler` from this row and calls
 * YusenHandler::setup() — so the connector ships no GraphQL of its own.
 *
 * There are no credentials: Yusen pushes their Item Balance XML to a receiver. Everything in
 * `config` is about what the discrepancy report compares their count against.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'yusen')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'primary_warehouse_id' => ['type' => 'text', 'required' => false],
            'netsuite_saved_search_id' => ['type' => 'text', 'required' => false],
            'match_field' => ['type' => 'text', 'required' => false],
            'quantity_tolerance' => ['type' => 'text', 'required' => false],
            'reconcile_with_netsuite' => ['type' => 'text', 'required' => false],
            'report_users' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'yusen',
            'handler' => 'Kanvas\\Connectors\\Yusen\\Handlers\\YusenHandler',
            'apps_id' => 0,
            'config' => json_encode($config),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'yusen')
            ->where('apps_id', 0)
            ->delete();
    }
};
