<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        $db = DB::connection('action_engine');

        $db->table('pipelines_stages')
            ->where('slug', 'open')
            ->update([
                'name' => 'read',
                'slug' => 'opened',
            ]);

        $db->table('pipelines_stages')
            ->where('slug', 'sent')
            ->where('name', 'Sent')
            ->update(['name' => 'shared']);
    }

    /**
     * Not reversible: `read|opened` and `shared|sent` are also what the Setup templates seed,
     * so rolling back would rename correctly-seeded stages too.
     */
    public function down(): void
    {
    }
};
