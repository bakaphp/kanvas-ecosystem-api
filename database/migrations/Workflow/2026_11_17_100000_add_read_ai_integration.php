<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers Read.ai against the generic integration mechanism; setup runs through the shared
 * `integrationCompany` mutation, which calls ReadAiHandler::setup(). It stores only the webhook's
 * signing key: the webhook itself is created by a workspace admin in Read.ai.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'read_ai')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'read_ai',
            'handler' => 'Kanvas\\Connectors\\ReadAi\\Handlers\\ReadAiHandler',
            'apps_id' => 0,
            'config' => json_encode([
                'signing_key' => ['type' => 'password', 'required' => true],
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
            ->where('name', 'read_ai')
            ->where('apps_id', 0)
            ->delete();
    }
};
