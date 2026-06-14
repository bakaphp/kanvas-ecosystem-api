<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('sales_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_receipt_id');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('sku', 64)->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);

            // Dual-stored amounts
            $table->decimal('unit_price_native',      18, 4)->default(0);
            $table->decimal('discount_amount_native', 18, 4)->default(0);
            $table->decimal('tax_amount_native',      18, 4)->default(0);
            $table->decimal('line_total_native',      18, 4)->default(0);

            $table->decimal('unit_price_base',      18, 4)->default(0);
            $table->decimal('discount_amount_base', 18, 4)->default(0);
            $table->decimal('tax_amount_base',      18, 4)->default(0);
            $table->decimal('line_total_base',      18, 4)->default(0);

            $table->decimal('discount_rate', 5, 4)->nullable();
            $table->decimal('tax_rate', 10, 6)->nullable();
            $table->json('tax_metadata')->nullable();

            // Per-line revenue account — overrides the item's default_income_account_id if set.
            // Composer's fallback order: this column → item.default_income_account_id → SERVICE_REVENUE sub-type.
            $table->unsignedBigInteger('income_account_id')->nullable();

            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sales_receipt_id'], 'srl_receipt_idx');
            $table->index(['item_id'], 'srl_item_idx');
            $table->index(['income_account_id'], 'srl_income_account_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('sales_receipt_lines');
    }
};
