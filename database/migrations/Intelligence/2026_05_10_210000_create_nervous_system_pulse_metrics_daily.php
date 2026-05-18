<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily snapshot of Pulse-dashboard metrics per (app, company).
 *
 * Deliberately separate from `nervous_system_dashboard_metrics_daily`
 * (the business-outcome snapshot) — Pulse measures operational throughput
 * (event volume, agent confidence, system response), Dashboard measures
 * business outcomes (accomplishments, value delivered, time recovered).
 * Different audiences, different cadence of revision, different formulas.
 * Keeping them apart prevents cross-contamination in rollup logic and
 * makes schema evolution per-surface independent.
 *
 * Counts default to 0. system_confidence_pct is nullable — null means
 * no plans were completed in the period, so confidence can't be measured.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->create('nervous_system_pulse_metrics_daily', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->date('metric_date');

            // Pulse counts — derived from ledger events grouped by category.
            $table->unsignedInteger('signals_count')->default(0);
            $table->unsignedInteger('understand_count')->default(0);
            $table->unsignedInteger('decide_count')->default(0);
            $table->unsignedInteger('actions_executed')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);

            // Prevented issues = plans with error_message + status=done in the window.
            $table->unsignedInteger('prevented_issues')->default(0);

            // Avg confidence_score × 100 across completed plans in the window.
            $table->decimal('system_confidence_pct', 5, 2)->nullable();

            // Audit
            $table->timestamp('computed_at');
            $table->string('formula_version', 16)->default('1.0');

            $table->unique(
                ['apps_id', 'companies_id', 'metric_date'],
                'uniq_pulse_metric_daily',
            );
            $table->index('metric_date', 'idx_pulse_metric_date');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->dropIfExists('nervous_system_pulse_metrics_daily');
    }
};
