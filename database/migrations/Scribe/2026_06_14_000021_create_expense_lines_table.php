<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('expense_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_id');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unsignedBigInteger('item_id')->nullable();      // optional FK to accounting.items
            $table->text('description')->nullable();

            $table->decimal('amount_native', 18, 4);
            $table->decimal('amount_base', 18, 4);
            $table->decimal('tax_amount_native', 18, 4)->default(0);
            $table->decimal('tax_amount_base', 18, 4)->default(0);

            // Which P&L expense account this line debits. NOT nullable — every expense line must hit some
            // expense account to balance the credit side (Cash / CC / Due to Employees / Bank).
            // Required at approval time; can be null in draft phase if not yet assigned.
            $table->unsignedBigInteger('expense_account_id')->nullable();

            // Dimensional tags (reserved nullable)
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['expense_id'], 'expl_expense_idx');
            $table->index(['expense_account_id'], 'expl_expense_account_idx');
            $table->index(['item_id'], 'expl_item_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('expense_lines');
    }
};
