<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Without this row the shared `integrationCompany` mutation has no `handler` to resolve, so a company
 * cannot connect its Twilio account from the integrations screen.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'twilio')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'account_sid' => ['type' => 'text', 'required' => true],
            'auth_token' => ['type' => 'password', 'required' => true],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'twilio',
            'handler' => 'Kanvas\\Connectors\\Twilio\\Handlers\\TwilioHandlers',
            'apps_id' => 0,
            'config' => json_encode($config),
            'type' => 'key',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'twilio')
            ->where('apps_id', 0)
            ->delete();
    }
};
