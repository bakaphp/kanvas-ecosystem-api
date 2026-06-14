<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unsignedBigInteger('item_id')->nullable();      // FK accounting.items.id
            $table->string('sku', 64)->nullable();                  // denormalized for historical accuracy
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);

            // Per-line dual-stored amounts (Round-4 #10)
            $table->decimal('unit_price_native',      18, 4)->default(0);
            $table->decimal('discount_amount_native', 18, 4)->default(0);
            $table->decimal('tax_amount_native',      18, 4)->default(0);
            $table->decimal('line_total_native',      18, 4)->default(0);

            $table->decimal('unit_price_base',      18, 4)->default(0);
            $table->decimal('discount_amount_base', 18, 4)->default(0);
            $table->decimal('tax_amount_base',      18, 4)->default(0);
            $table->decimal('line_total_base',      18, 4)->default(0);

            $table->decimal('discount_rate', 5, 4)->nullable();     // Round-6 M6 — percentage discount
            $table->decimal('tax_rate', 10, 6)->nullable();
            $table->json('tax_metadata')->nullable();

            // Dimensional tags (Round-5 reserved nullable)
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            // Linked-transaction reference (Round-6 M1 — Phase 3)
            $table->unsignedBigInteger('linked_bill_line_id')->nullable();
            $table->decimal('linked_markup_rate', 5, 4)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['invoice_id'], 'invoice_lines_invoice_idx');
            $table->index(['item_id'], 'invoice_lines_item_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('invoice_lines');
    }
};
