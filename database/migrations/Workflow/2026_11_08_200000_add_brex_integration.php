<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers Brex against the generic integration mechanism; setup runs through the shared `integrationCompany`
 * mutation, which calls BrexHandler::setup(). The connector only reads (bank feed), so a Brex user token with
 * the read-only account and transaction scopes is enough.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'brex')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'brex',
            'handler' => 'Kanvas\\Connectors\\Brex\\Handlers\\BrexHandler',
            'apps_id' => 0,
            'config' => json_encode([
                'api_token' => ['type' => 'password', 'required' => true],
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
            ->where('name', 'brex')
            ->where('apps_id', 0)
            ->delete();
    }
};
