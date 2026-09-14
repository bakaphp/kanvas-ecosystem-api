<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers Tavily against the generic integration mechanism. Without this row the shared
 * `integrationCompany` mutation has no `handler` to resolve, so TavilyHandler::setup() is
 * unreachable and the only way to configure the key is writing the app setting by hand.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'tavily')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'api_key' => ['type' => 'text', 'required' => true],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'tavily',
            'handler' => 'Kanvas\\Connectors\\Tavily\\Handlers\\TavilyHandler',
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
            ->where('name', 'tavily')
            ->where('apps_id', 0)
            ->delete();
    }
};
