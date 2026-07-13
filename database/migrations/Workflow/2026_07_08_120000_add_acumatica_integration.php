<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'acumatica')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            // REST (write-back) — service-account auth
            'base_url' => ['type' => 'text', 'required' => true],
            'username' => ['type' => 'text', 'required' => true],
            'password' => ['type' => 'text', 'required' => true],
            'company' => ['type' => 'text', 'required' => true],
            'endpoint_name' => ['type' => 'text', 'required' => false],
            'endpoint_version' => ['type' => 'text', 'required' => false],
            'branch' => ['type' => 'text', 'required' => false],
            // SQL read replica (raw dbo.* reads) — optional at setup, may be provisioned later
            'sql_host' => ['type' => 'text', 'required' => false],
            'sql_port' => ['type' => 'text', 'required' => false],
            'sql_database' => ['type' => 'text', 'required' => false],
            'sql_username' => ['type' => 'text', 'required' => false],
            'sql_password' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'acumatica',
            'handler' => 'Kanvas\\Connectors\\Acumatica\\Handlers\\AcumaticaHandler',
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
            ->where('name', 'acumatica')
            ->where('apps_id', 0)
            ->delete();
    }
};
