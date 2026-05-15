<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        $exists = DB::connection('intelligence')
            ->table('agent_types')
            ->where('provider', 'hermes')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('intelligence')->table('agent_types')->insert([
            'apps_id' => 0,
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'name' => 'Hermes Agent',
            'description' => 'AI agent powered by Hermes — supports deployment, terminal access, and remote management.',
            'provider' => 'hermes',
            'handler' => null,
            'config' => json_encode([]),
            'role' => json_encode([]),
            'is_active' => 1,
            'is_published' => 1,
            'is_multi_agent' => 0,
            'multi_agent_list' => json_encode([]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('intelligence')
            ->table('agent_types')
            ->where('provider', 'hermes')
            ->where('apps_id', 0)
            ->delete();
    }
};
