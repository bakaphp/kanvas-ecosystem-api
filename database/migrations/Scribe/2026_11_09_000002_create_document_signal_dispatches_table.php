<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One customer signal on one document handed to the AR agent as a task. The unique signal_key is what
 * stops two callers from opening two tasks; a row stays retryable until the task exists.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('document_signal_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->string('signal_key', 191);
            $table->string('document_type', 16);
            $table->unsignedBigInteger('document_id');
            $table->string('signal', 64);
            $table->text('brief');
            $table->timestamp('due_at')->nullable();
            $table->string('status', 16);
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('signal_key', 'document_signal_dispatches_key_unique');
            $table->index(['status', 'next_retry_at'], 'document_signal_dispatches_retry_idx');
            $table->index(['document_type', 'document_id'], 'document_signal_dispatches_document_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('document_signal_dispatches');
    }
};
