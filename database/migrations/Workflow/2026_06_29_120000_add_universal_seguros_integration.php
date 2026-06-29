<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'universal_seguros')->where('apps_id', 0)->exists()) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'universal_seguros',
            'handler' => 'Kanvas\\Connectors\\UniversalSeguros\\Handlers\\UniversalSegurosHandler',
            'apps_id' => 0,
            'config' => json_encode([
                'environment' => ['type' => 'text', 'required' => true],
                'client_id' => ['type' => 'text', 'required' => true],
                'client_secret' => ['type' => 'text', 'required' => true],
                'scopes' => ['type' => 'text', 'required' => false],
            ]),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'universal_seguros')
            ->where('apps_id', 0)
            ->delete();
    }
};
