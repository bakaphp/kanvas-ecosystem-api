<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt attachments — pointers into the Kanvas Filesystem (which lives in the main `mysql` DB).
 * Cross-DB logical FK; no DDL constraint.
 *
 * Allows multiple receipts per expense (boarding pass + hotel receipt + meal receipts for one trip).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('expense_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_id');

            $table->unsignedBigInteger('filesystem_id');           // logical FK → kanvas.filesystem.id

            $table->timestamp('uploaded_at');
            $table->unsignedInteger('uploaded_by_users_id')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['expense_id'], 'expr_expense_idx');
            $table->index(['filesystem_id'], 'expr_filesystem_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('expense_receipts');
    }
};
