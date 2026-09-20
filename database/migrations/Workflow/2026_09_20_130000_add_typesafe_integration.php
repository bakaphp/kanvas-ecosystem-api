<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Registers TypeSafe against the generic integration mechanism. Without this row the shared
 * `integrationCompany` mutation has no `handler` to resolve, so TypeSafeHandler::setup() is
 * unreachable and the only way to configure the key is writing the app setting by hand.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'typesafe')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'api_key' => ['type' => 'text', 'required' => true],
            'model' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'typesafe',
            'handler' => 'Kanvas\\Connectors\\TypeSafe\\Handlers\\TypeSafeHandler',
            'apps_id' => 0,
            'type' => IntegrationTypeEnum::KEY->value,
            'config' => json_encode($config),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'typesafe')
            ->where('apps_id', 0)
            ->delete();
    }
};
