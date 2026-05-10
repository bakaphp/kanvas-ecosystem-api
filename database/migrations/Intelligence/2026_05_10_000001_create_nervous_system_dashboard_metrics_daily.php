<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily rollup of dashboard metrics per (app, company). One row per
 * (apps_id, companies_id, metric_date) — the unique key enforces
 * idempotency so the rollup job can re-run safely.
 *
 * Read strategy: today is always live-aggregated; past days come from
 * this table. See DashboardMetricsService for the orchestrator.
 *
 * Counts default to 0 (zero is a valid value). Output-derived metrics
 * are nullable — null means "no plan in the day populated the JSON
 * key", distinct from 0 which means "we measured 0 contribution".
 *
 * formula_version lets us evolve aggregation logic without rewriting
 * history. Old rows stay readable at their original version.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->create('nervous_system_dashboard_metrics_daily', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->date('metric_date');

            $table->unsignedInteger('plans_completed')->default(0);
            $table->unsignedInteger('mistakes_auto_corrected')->default(0);
            $table->unsignedInteger('mistakes_escalated')->default(0);
            $table->unsignedInteger('agents_active')->default(0);

            $table->decimal('time_recovered_hours', 10, 2)->nullable();
            $table->unsignedBigInteger('value_delivered_cents')->nullable();
            $table->decimal('estimated_human_hours', 10, 2)->nullable();

            $table->timestamp('computed_at');
            $table->string('formula_version', 16)->default('1.0');

            $table->unique(
                ['apps_id', 'companies_id', 'metric_date'],
                'uniq_dashboard_metric_daily',
            );
            $table->index('metric_date', 'idx_dashboard_metric_date');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_dashboard_metrics_daily');
    }
};
