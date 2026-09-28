<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    /**
     * AppKey callers (system context) scope ledgerEvents with `companies_id > 0` — a range
     * predicate that stops the composite `apps_company_occurred (apps_id, companies_id, occurred_at)`
     * index from serving `ORDER BY occurred_at DESC`. MySQL then filesorts the whole `select *`
     * (including the large payload/result JSON) and blows the read-replica sort buffer
     * (HY001 1038 Out of sort memory — Sentry KANVAS-ECOSYSTEM-5YP).
     *
     * A leading `(apps_id, occurred_at)` index lets that path resolve via an index-ordered scan:
     * apps_id equality + occurred_at range/order, with companies_id > 0 as a residual filter.
     * Makes the single-column `apps_id_idx` redundant (covered as leftmost prefix).
     */
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->index(['apps_id', 'occurred_at'], 'apps_occurred');
            $table->dropIndex('apps_id_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->index(['apps_id'], 'apps_id_idx');
            $table->dropIndex('apps_occurred');
        });
    }
};
