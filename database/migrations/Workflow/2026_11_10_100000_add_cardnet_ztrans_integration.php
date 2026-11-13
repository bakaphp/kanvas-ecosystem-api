<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'cardnet_ztrans')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'cardnet_ztrans',
            'handler' => 'Kanvas\Connectors\CardNet\Ztrans\Handlers\CardNetZtransHandler',
            'apps_id' => 0,
            'config' => json_encode([
                'base_url' => ['type' => 'text', 'required' => true],
                'merchant_id' => ['type' => 'text', 'required' => true],
                'terminal_id' => ['type' => 'text', 'required' => true],
                'three_ds_base_url' => ['type' => 'text', 'required' => true],
                'three_ds_integrator_code' => ['type' => 'text', 'required' => true],
                'three_ds_api_key' => ['type' => 'password', 'required' => true],
                'three_ds_mode' => ['type' => 'text', 'required' => false],
                'use_hold' => ['type' => 'text', 'required' => false],
                'refund_bearer_token' => ['type' => 'password', 'required' => false],
            ]),
            'type' => 'key',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'cardnet_ztrans')
            ->where('apps_id', 0)
            ->delete();
    }
};
