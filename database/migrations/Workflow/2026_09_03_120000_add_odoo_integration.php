<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers Odoo against the generic integration mechanism, same shape as
 * `2026_07_15_120000_add_salesforce_integration.php`. Setup runs through the shared
 * `integrationCompany` mutation, which reads `handler` from this row and calls
 * OdooHandler::setup() — so the connector ships no GraphQL of its own.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'odoo')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'url' => ['type' => 'text', 'required' => true],
            'db' => ['type' => 'text', 'required' => true],
            'username' => ['type' => 'text', 'required' => true],
            'api_key' => ['type' => 'text', 'required' => true],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'odoo',
            'handler' => 'Kanvas\\Connectors\\Odoo\\Handlers\\OdooHandler',
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
            ->where('name', 'odoo')
            ->where('apps_id', 0)
            ->delete();
    }
};
