<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bills sub-ledger — vendor-side AP. Mirrors Invoices structurally but flipped:
 *   - vendor (PayeeInterface) instead of customer (BillableInterface)
 *   - bill_number is the VENDOR's number (opaque to us — they assign it on their docs)
 *   - DR Expense + DR Input Tax / CR AP at receive time (vs Invoices' DR AR / CR Revenue)
 *   - no delivery_status (we don't send Bills outbound)
 *   - one less status (no SENT — vendor delivers, we receive)
 *
 * State lifecycle:  DRAFT → RECEIVED → PAID
 *                              ↓
 *                            VOIDED (mirror reversal JE)
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            // The vendor's own invoice number (opaque to us — they assigned it)
            $table->string('bill_number', 64)->nullable();

            // Polymorphic vendor — Guild.Organization or Guild.People (see §4.1)
            $table->string('vendor_billable_type', 64)->nullable();
            $table->unsignedBigInteger('vendor_billable_id')->nullable();

            // Vendor snapshot (immutable post-receive)
            $table->string('vendor_display_name', 191)->nullable();
            $table->string('vendor_legal_name', 191)->nullable();
            $table->string('vendor_tax_id', 64)->nullable();
            $table->string('vendor_email', 191)->nullable();
            $table->json('vendor_address_snapshot')->nullable();

            // Two-axis status
            $table->enum('document_status', ['draft', 'received', 'paid', 'voided'])->default('draft');
            $table->enum('collection_state', ['current', 'overdue', 'disputed', 'uncollectible'])->nullable();

            // Tax semantics
            $table->enum('tax_calculation_mode', ['exclusive', 'inclusive', 'not_applicable'])->default('exclusive');

            // Dates
            $table->date('bill_date')->nullable();               // vendor's issue date (per their bill)
            $table->date('received_date')->nullable();           // we entered it in our system
            $table->date('due_date')->nullable();
            $table->date('scheduled_payment_date')->nullable();  // when we plan to pay (our internal target)
            $table->unsignedSmallInteger('net_terms_days')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason_code', 64)->nullable();

            // Currency + FX
            $table->string('currency', 3);
            $table->decimal('fx_rate_to_base', 20, 10);
            $table->timestamp('fx_rate_at')->nullable();

            // Native amounts
            $table->decimal('subtotal_native', 18, 4)->default(0);
            $table->decimal('tax_native', 18, 4)->default(0);
            $table->decimal('discount_native', 18, 4)->default(0);
            $table->decimal('total_native', 18, 4)->default(0);
            $table->decimal('paid_native', 18, 4)->default(0);
            $table->decimal('balance_due_native', 18, 4)->default(0);

            // Base amounts
            $table->decimal('subtotal_base', 18, 4)->default(0);
            $table->decimal('tax_base', 18, 4)->default(0);
            $table->decimal('discount_base', 18, 4)->default(0);
            $table->decimal('total_base', 18, 4)->default(0);
            $table->decimal('paid_base', 18, 4)->default(0);
            $table->decimal('balance_due_base', 18, 4)->default(0);

            $table->json('tax_metadata')->nullable();
            $table->json('regional_compliance')->nullable();

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('terms')->nullable();

            // Source + anti-loop
            $table->enum('source', ['kanvas', 'adm_cloud', 'quickbooks', 'netsuite', 'xero', 'parsed_pdf', 'manual'])->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->enum('origin', ['external', 'kanvas'])->default('kanvas');

            // Document links
            $table->unsignedBigInteger('purchase_order_id')->nullable();   // reserved — PO module is Phase 3
            $table->unsignedBigInteger('pdf_ingest_log_id')->nullable();   // back-link when ingested via PDF flow

            $table->json('metadata')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['apps_id', 'companies_id', 'vendor_billable_type', 'vendor_billable_id', 'bill_number'],
                'bills_vendor_number_uq'
            );
            $table->unique(
                ['apps_id', 'source', 'external_id'],
                'bills_app_source_external_uq'
            );

            $table->index(['apps_id', 'companies_id', 'document_status', 'due_date'], 'bills_app_company_status_due_idx');
            $table->index(['apps_id', 'companies_id', 'vendor_billable_type', 'vendor_billable_id'], 'bills_app_company_vendor_idx');
            $table->index(['uuid'], 'bills_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('bills');
    }
};
