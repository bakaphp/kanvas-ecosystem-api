<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'onedrive')->where('apps_id', 0)->exists()) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'onedrive',
            'type' => 'oauth',
            'handler' => 'Kanvas\\Connectors\\Microsoft\\Handlers\\OneDriveHandler',
            'apps_id' => 0,
            'config' => json_encode([
                'root_folder' => ['type' => 'text', 'required' => false],
            ]),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'onedrive')
            ->where('apps_id', 0)
            ->delete();
    }
};
