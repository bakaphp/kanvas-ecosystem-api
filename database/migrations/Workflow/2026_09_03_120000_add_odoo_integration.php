<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Setup runs through the shared `integrationCompany` mutation, which reads `handler` from this
 * row and calls `OdooHandler::setup()` — which is why the connector ships no GraphQL of its own.
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
