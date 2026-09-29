<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'intras';
    private const string HANDLER = 'Kanvas\\Connectors\\Intras\\Handlers\\IntrasHandler';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => 0,
            'name' => self::NAME,
            'handler' => self::HANDLER,
            'config' => json_encode([
                'intras_db_host' => ['type' => 'text', 'required' => true],
                'intras_db_database' => ['type' => 'text', 'required' => true],
                'intras_db_username' => ['type' => 'text', 'required' => false],
                'intras_db_password' => ['type' => 'password', 'required' => false],
            ]),
            'created_at' => now(),
            'is_deleted' => 0,
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->delete();
    }
};
