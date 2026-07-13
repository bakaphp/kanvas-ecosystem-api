<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers Mercury against the generic integration mechanism. Setup runs through the shared
 * `integrationCompany` mutation, which reads `handler` from this row and calls MercuryHandler::setup() —
 * so the connector ships no GraphQL of its own.
 *
 * A read-only Mercury token is sufficient: the connector only reads. It never moves money.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'mercury')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'api_token' => ['type' => 'text', 'required' => true],
            'base_url' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'mercury',
            'handler' => 'Kanvas\\Connectors\\Mercury\\Handlers\\MercuryHandler',
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
            ->where('name', 'mercury')
            ->where('apps_id', 0)
            ->delete();
    }
};
