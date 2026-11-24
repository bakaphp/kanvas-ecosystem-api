<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring billing and signed-SOW payment schedules. A daily job turns each due period into one
 * DRAFT invoice; billing_schedule_runs is what makes that idempotent per period. Lines are typed rows in
 * billing_schedule_lines.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('billing_schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedInteger('users_id')->nullable();
            $table->uuid('uuid');

            $table->unsignedBigInteger('customer_organization_id');
            $table->string('name', 191)->nullable();
            $table->string('currency', 3);

            $table->enum('cadence', ['monthly', 'quarterly', 'yearly', 'once']);
            $table->unsignedTinyInteger('anchor_day')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_run_at')->nullable();
            $table->unsignedSmallInteger('net_terms_days')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('source_quote_id')->nullable();
            $table->string('source_key', 64)->nullable();

            $table->enum('status', ['active', 'paused', 'finished'])->default('active');
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'status', 'next_run_at'], 'billing_schedules_due_idx');
            $table->index(['apps_id', 'companies_id', 'customer_organization_id'], 'billing_schedules_customer_idx');
            $table->unique(['source_quote_id', 'source_key'], 'billing_schedules_source_unique');
            $table->unique('uuid');
        });

        Schema::connection('accounting')->create('billing_schedule_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('billing_schedule_id');
            $table->date('period_start');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['billing_schedule_id', 'period_start'], 'billing_schedule_runs_period_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('billing_schedule_runs');
        Schema::connection('accounting')->dropIfExists('billing_schedules');
    }
};
