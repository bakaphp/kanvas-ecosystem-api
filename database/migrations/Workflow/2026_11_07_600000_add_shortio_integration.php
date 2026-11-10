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
            ->where('name', 'shortio')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'shortio',
            'handler' => 'Kanvas\\Connectors\\ShortIo\\Handlers\\ShortIoHandler',
            'apps_id' => 0,
            'config' => json_encode([
                'api_key' => ['type' => 'password', 'required' => true],
                'domain' => ['type' => 'text', 'required' => true],
                'link_page_base_url' => ['type' => 'text', 'required' => false],
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
            ->where('name', 'shortio')
            ->where('apps_id', 0)
            ->delete();
    }
};
