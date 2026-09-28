<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's batch bookkeeping. Nothing in this codebase used `Bus::batch()` before the task-band
 * fan-out, so the table it needs has never been created.
 *
 * Default connection deliberately: batches track queue state, not domain state, and the queue is the
 * default connection's concern.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('job_batches')) {
            return;
        }

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batches');
    }
};
