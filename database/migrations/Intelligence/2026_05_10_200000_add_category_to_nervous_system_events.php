<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialize the Pulse-stream category as a real column so the daily
 * rollup can `GROUP BY category` cheaply. Previously category was a
 * derived accessor recomputed on every read.
 *
 * Backfill of existing rows runs via:
 *   php artisan kanvas:backfill-ledger-event-categories
 *
 * The model's `creating` hook writes the column on every new event so
 * the column stays in sync going forward. The accessor still falls
 * back to Event::categoryFor() for any row where the column is null
 * (graceful read during backfill window).
 *
 * No new composite index — the existing apps_company_occurred index
 * already covers the rollup query's WHERE clause; the per-day result
 * set is small enough that GROUP BY category runs in memory.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->string('category', 16)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
