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
            // Number of entries in payload.changed_fields, materialized so the change feed can
            // filter to events that actually carry a change (and paginate correctly) without a
            // per-row JSON scan. 0 for events with no changes (or no changed_fields at all).
            $table->unsignedSmallInteger('change_count')->default(0)->after('payload_schema_version');
            $table->index(['apps_id', 'companies_id', 'event_type', 'change_count'], 'apps_company_type_changecount');
        });

        // Backfill existing change-bearing events (people.enriched is the only type that carries
        // changed_fields today). Bounded to those rows, not the whole ledger.
        DB::connection('intelligence')->statement(
            "UPDATE nervous_system_events
             SET change_count = COALESCE(JSON_LENGTH(JSON_EXTRACT(payload, '$.changed_fields')), 0)
             WHERE JSON_EXTRACT(payload, '$.changed_fields') IS NOT NULL"
        );
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->dropIndex('apps_company_type_changecount');
            $table->dropColumn('change_count');
        });
    }
};
