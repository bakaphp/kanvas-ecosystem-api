<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run row is written before the schedule advances, so a period whose approval submission failed is
 * still found (status failed) and resumed by the next run instead of being skipped as already billed.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('billing_schedule_runs', function (Blueprint $table): void {
            $table->string('status', 16)->default('submitted')->after('invoice_id');
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->text('last_error')->nullable()->after('attempts');
            $table->timestamp('next_retry_at')->nullable()->after('last_error');

            $table->index(['status', 'next_retry_at'], 'billing_schedule_runs_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('billing_schedule_runs', function (Blueprint $table): void {
            $table->dropIndex('billing_schedule_runs_retry_idx');
            $table->dropColumn(['status', 'attempts', 'last_error', 'next_retry_at']);
        });
    }
};
