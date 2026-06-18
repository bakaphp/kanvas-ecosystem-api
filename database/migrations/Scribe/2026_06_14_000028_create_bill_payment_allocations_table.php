<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps one Souk.Payments (or vendor_credit, prepayment) outbound to N bills.
 * Mirrors invoice_payment_allocations on the vendor side. Reversals are status flips, never row deletes.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('bill_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->unsignedBigInteger('bill_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->enum('source_type', ['souk_payment', 'vendor_credit', 'prepayment', 'wallet', 'manual'])
                ->default('souk_payment');
            $table->unsignedBigInteger('source_id')->nullable();

            $table->enum('status', ['pending', 'active', 'reversed'])->default('active');

            $table->decimal('amount_native', 18, 4);
            $table->decimal('amount_base', 18, 4);
            $table->string('currency', 3);
            $table->decimal('fx_rate_to_base', 20, 10);
            $table->timestamp('fx_rate_at')->nullable();

            $table->timestamp('allocated_at')->nullable();
            $table->unsignedInteger('allocated_by_users_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedInteger('reversed_by_users_id')->nullable();
            $table->string('reversal_reason', 64)->nullable();
            $table->string('reversal_reason_code', 64)->nullable();
            $table->string('reversal_external_id', 191)->nullable();

            $table->enum('source', ['kanvas', 'adm_cloud', 'manual'])->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'idempotency_key'], 'bill_alloc_idem_uq');
            $table->index(['bill_id', 'status'], 'bill_alloc_bill_status_idx');
            $table->index(['payment_id'], 'bill_alloc_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('bill_payment_allocations');
    }
};
