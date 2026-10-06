<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'azul')->exists()) {
            return;
        }

        $config = [
            'auth1' => ['type' => 'password', 'required' => true],
            'auth2' => ['type' => 'password', 'required' => true],
            'store' => ['type' => 'text', 'required' => true],
            'channel' => ['type' => 'text', 'required' => true],
            'base_url' => ['type' => 'text', 'required' => false],
            'failover_url' => ['type' => 'text', 'required' => false],
            'cert' => ['type' => 'text', 'required' => false],
            'key' => ['type' => 'password', 'required' => false],
            'ca' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'azul',
            'handler' => 'Kanvas\\Connectors\\Azul\\Handlers\\AzulHandler',
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
            ->where('name', 'azul')
            ->where('apps_id', 0)
            ->delete();
    }
};
