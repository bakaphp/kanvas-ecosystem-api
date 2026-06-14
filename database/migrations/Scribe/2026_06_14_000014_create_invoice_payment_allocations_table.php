<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps one Souk.Payments (or credit_note, prepayment, overpayment, wallet) to N invoices with allocated amounts.
 *
 * Lifecycle (Round-4 #2 + Round-6 C7):
 *   - status: pending | active | reversed
 *   - reversal_reason_code: bounce | chargeback | customer_dispute | bank_error | fraud | duplicate | administrative | other
 *
 * Idempotency:
 *   - UNIQUE (apps_id, source, external_id) — external systems (Stripe/ADM Cloud/etc.) keyed by their own id
 *   - UNIQUE (apps_id, idempotency_key) — API-driven manual recording
 *
 * @see plan §5 accounting.invoice_payment_allocations
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('invoice_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('payment_id')->nullable();  // souk.payments.id; nullable for non-payment sources

            // What kind of credit is this allocation drawn from?
            $table->enum('source_type', [
                'souk_payment',
                'credit_note',
                'prepayment',       // Round-6 C1
                'overpayment',      // Round-6 C1
                'wallet',
                'manual',
            ])->default('souk_payment');
            $table->unsignedBigInteger('source_id')->nullable();   // FK target for non-souk_payment kinds

            $table->enum('status', ['pending', 'active', 'reversed'])->default('active');

            $table->decimal('amount_native', 18, 4);
            $table->decimal('amount_base',   18, 4);
            $table->string('currency', 3);
            $table->decimal('fx_rate_to_base', 20, 10);
            $table->timestamp('fx_rate_at')->nullable();

            $table->timestamp('allocated_at');
            $table->unsignedInteger('allocated_by_users_id')->nullable();  // NULL = system/agent

            // Reversal (Round-6 C7 — NSF/chargeback/dispute taxonomy)
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedInteger('reversed_by_users_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->enum('reversal_reason_code', [
                'bounce', 'chargeback', 'customer_dispute', 'bank_error',
                'fraud', 'duplicate', 'administrative', 'other',
            ])->nullable();
            $table->string('reversal_external_id', 191)->nullable();

            // Idempotency
            $table->enum('source', ['kanvas', 'stripe', 'adm_cloud', 'manual', 'wallet'])->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->string('idempotency_key', 191)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'source', 'external_id'], 'ipa_app_source_external_uq');
            $table->unique(['apps_id', 'idempotency_key'], 'ipa_app_idempotency_uq');

            $table->index(['payment_id'], 'ipa_payment_idx');
            $table->index(['invoice_id'], 'ipa_invoice_idx');
            $table->index(['apps_id', 'companies_id', 'status'], 'ipa_app_company_status_idx');
            $table->index(['apps_id', 'companies_id', 'reversal_reason_code'], 'ipa_app_company_reversal_reason_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('invoice_payment_allocations');
    }
};
