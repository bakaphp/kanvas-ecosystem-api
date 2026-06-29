<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('bill_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('sku', 64)->nullable();
            $table->text('description');
            $table->decimal('quantity', 18, 4)->default(1);

            // Per-line dual-stored amounts
            $table->decimal('unit_price_native', 18, 4)->default(0);
            $table->decimal('discount_amount_native', 18, 4)->default(0);
            $table->decimal('tax_amount_native', 18, 4)->default(0);
            $table->decimal('line_total_native', 18, 4)->default(0);

            $table->decimal('unit_price_base', 18, 4)->default(0);
            $table->decimal('discount_amount_base', 18, 4)->default(0);
            $table->decimal('tax_amount_base', 18, 4)->default(0);
            $table->decimal('line_total_base', 18, 4)->default(0);

            $table->decimal('discount_rate', 5, 4)->nullable();
            $table->decimal('tax_rate', 10, 6)->nullable();

            // GL routing — which expense (or asset) account this line debits when received.
            // Required when status flips to RECEIVED — composer validates.
            $table->unsignedBigInteger('expense_account_id')->nullable();

            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->json('tax_metadata')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['bill_id'], 'bill_lines_bill_idx');
            $table->index(['item_id'], 'bill_lines_item_idx');
            $table->index(['expense_account_id'], 'bill_lines_expense_account_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('bill_lines');
    }
};
