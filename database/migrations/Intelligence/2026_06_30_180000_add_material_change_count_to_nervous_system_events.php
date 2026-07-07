<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            // Count of REAL before/after changes only (excludes flags like new_account) — equals the
            // number of rows the change feed renders. Frontend filters `material_change_count > 0`.
            $table->unsignedSmallInteger('material_change_count')->default(0)->after('change_count');
            $table->index(['apps_id', 'companies_id', 'event_type', 'material_change_count'], 'apps_company_type_material');
        });

        // One-time historical backfill. Runtime uses the generic { from, to } rule
        // (Event::countMaterialChanges); here we count the four before/after keys explicitly —
        // for existing rows they are provably the only from/to objects, so the result is identical.
        DB::connection('intelligence')->statement(
            "UPDATE nervous_system_events
             SET material_change_count =
                   (JSON_CONTAINS(payload->'$.changed_fields','\"current_employer\"')=1)
                 + (JSON_CONTAINS(payload->'$.changed_fields','\"title\"')=1)
                 + (JSON_CONTAINS(payload->'$.changed_fields','\"email_changed\"')=1)
                 + (JSON_CONTAINS(payload->'$.changed_fields','\"seniority_promoted\"')=1)
             WHERE JSON_EXTRACT(payload,'$.changed_fields') IS NOT NULL"
        );
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->dropIndex('apps_company_type_material');
            $table->dropColumn('material_change_count');
        });
    }
};
