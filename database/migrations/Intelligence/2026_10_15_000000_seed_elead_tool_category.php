<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    protected $connection = 'intelligence';

    private const string SLUG = 'elead';

    /**
     * `kanvas:nervous-system:sync-tools` would create the row itself, but as "Elead" with no
     * description; the dashboard groups grants by this row, so it gets its display name here.
     */
    public function up(): void
    {
        $table = DB::connection('intelligence')->table('nervous_system_tool_categories');

        if ($table->where('apps_id', 0)->where('slug', self::SLUG)->exists()) {
            return;
        }

        $now = now();
        $table->insert([
            'apps_id' => 0,
            'slug' => self::SLUG,
            'name' => 'eLead',
            'description' => 'Live reads of the eLead dealer CRM: appointments, activity history, opportunity state, and a refresh into Kanvas.',
            'icon' => 'car',
            'display_order' => 15,
            'is_active' => true,
            'is_deleted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::connection('intelligence')
            ->table('nervous_system_tool_categories')
            ->where('apps_id', 0)
            ->where('slug', self::SLUG)
            ->delete();
    }
};
