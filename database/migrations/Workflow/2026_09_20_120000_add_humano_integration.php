<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'humano')->where('apps_id', 0)->exists()) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'humano',
            'handler' => 'Kanvas\\Connectors\\Humano\\Handlers\\HumanoHandler',
            'apps_id' => 0,
            'config' => json_encode([
                'environment' => ['type' => 'text', 'required' => true],
                'subscription_key' => ['type' => 'text', 'required' => true],
                'user_key' => ['type' => 'text', 'required' => true],
                'mediator_code' => ['type' => 'text', 'required' => true],
                'insurer_companies_id' => ['type' => 'text', 'required' => true],
            ]),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'humano')
            ->where('apps_id', 0)
            ->delete();
    }
};
