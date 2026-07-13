<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-order lines — what the agent matches an invoice line against (sku, open qty, unit cost)
 * and the GL coding it inherits when the PO carries one (expense_account_id + subaccount_id, present
 * on expense/service POs, null on inventory POs). Replaced wholesale when the parent PO is re-synced.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->integer('line_number');

            $table->string('sku', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('inventory_variant_id')->nullable();

            $table->unsignedBigInteger('expense_account_id')->nullable();
            $table->unsignedBigInteger('subaccount_id')->nullable();

            $table->decimal('order_qty', 18, 6)->default(0);
            $table->decimal('open_qty', 18, 6)->default(0);
            $table->decimal('received_qty', 18, 6)->default(0);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('ext_cost', 18, 4)->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id'], 'pol_po_idx');
            $table->index(['sku'], 'pol_sku_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('purchase_order_lines');
    }
};
