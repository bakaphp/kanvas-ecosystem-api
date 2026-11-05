<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers vAuto against the generic integration mechanism. Setup runs through the shared
 * `integrationCompany` mutation, which reads `handler` from this row and calls VAutoHandler::setup().
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'vauto')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'access_id' => ['type' => 'text', 'required' => true],
            'secret_key' => ['type' => 'text', 'required' => true],
            'entity_id' => ['type' => 'text', 'required' => true],
            'user_name' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'vauto',
            'handler' => 'Kanvas\\Connectors\\VAuto\\Handlers\\VAutoHandler',
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
            ->where('name', 'vauto')
            ->where('apps_id', 0)
            ->delete();
    }
};
