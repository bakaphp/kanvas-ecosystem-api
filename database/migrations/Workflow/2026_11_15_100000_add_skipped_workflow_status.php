<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A run that had nothing to do is recorded as `skipped`, kept apart from `failed`.
 */
return new class () extends Migration {
    public function up(): void
    {
        $status = DB::connection('workflow')->table('status');

        if ((clone $status)->where('slug', 'skipped')->where('apps_id', 0)->exists()) {
            return;
        }

        $status->insert([
            'name' => 'skipped',
            'slug' => 'skipped',
            'apps_id' => 0,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('status')->where('slug', 'skipped')->where('apps_id', 0)->delete();
    }
};
