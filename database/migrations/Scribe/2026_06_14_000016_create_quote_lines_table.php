<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quote_id');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('sku', 64)->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);

            // Dual-stored amounts
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
            $table->json('tax_metadata')->nullable();

            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['quote_id'], 'quote_lines_quote_idx');
            $table->index(['item_id'], 'quote_lines_item_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('quote_lines');
    }
};
