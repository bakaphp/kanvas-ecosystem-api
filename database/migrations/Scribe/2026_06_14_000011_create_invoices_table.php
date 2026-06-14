<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            // One table, document_type discriminator (Round-4 #8)
            $table->enum('document_type', ['invoice', 'credit_note'])->default('invoice');
            $table->string('invoice_number', 64)->nullable();

            // Polymorphic billable — Guild.Organization or Guild.People (see §4.1)
            $table->string('billable_type', 64)->nullable();
            $table->unsignedBigInteger('billable_id')->nullable();

            // Billable snapshot (immutable post-issue, Round-4 #6)
            $table->string('billable_display_name', 191)->nullable();
            $table->string('billable_legal_name', 191)->nullable();
            $table->string('billable_tax_id', 64)->nullable();
            $table->string('billable_email', 191)->nullable();
            $table->json('billing_address_snapshot')->nullable();
            $table->json('shipping_address_snapshot')->nullable();

            // Two-axis status (Round-4 #4)
            $table->enum('document_status', ['draft', 'issued', 'sent', 'paid', 'voided'])->default('draft');
            $table->enum('collection_state', ['current', 'overdue', 'disputed', 'uncollectible'])->nullable();

            // Tax + delivery semantics (Round-6 C8, C9)
            $table->enum('tax_calculation_mode', ['exclusive', 'inclusive', 'not_applicable'])->default('exclusive');
            $table->enum('delivery_status', ['not_applicable', 'needs_send', 'sent', 'bounced', 'opened'])->default('not_applicable');
            $table->timestamp('delivery_last_attempt_at')->nullable();
            $table->string('delivery_bounce_reason', 191)->nullable();

            // Dates
            $table->date('expected_payment_date')->nullable();
            $table->date('issued_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('net_terms_days')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason_code', 64)->nullable();

            // Currency + FX
            $table->string('currency', 3);
            $table->decimal('fx_rate_to_base', 20, 10);
            $table->timestamp('fx_rate_at')->nullable();

            // Native amounts (DECIMAL(18,4))
            $table->decimal('subtotal_native',     18, 4)->default(0);
            $table->decimal('tax_native',          18, 4)->default(0);
            $table->decimal('discount_native',     18, 4)->default(0);
            $table->decimal('total_native',        18, 4)->default(0);
            $table->decimal('paid_native',         18, 4)->default(0);
            $table->decimal('balance_due_native',  18, 4)->default(0);

            // Base amounts (DECIMAL(18,4))
            $table->decimal('subtotal_base',     18, 4)->default(0);
            $table->decimal('tax_base',          18, 4)->default(0);
            $table->decimal('discount_base',     18, 4)->default(0);
            $table->decimal('total_base',        18, 4)->default(0);
            $table->decimal('paid_base',         18, 4)->default(0);
            $table->decimal('balance_due_base',  18, 4)->default(0);

            $table->json('tax_metadata')->nullable();              // verbatim external tax fields
            $table->json('regional_compliance')->nullable();       // NCF/CFDI/NFE — validated by per-region validator
            $table->unsignedBigInteger('parent_invoice_grouping_id')->nullable();  // reserved for project hierarchy

            $table->text('notes')->nullable();                     // customer-facing
            $table->text('internal_notes')->nullable();            // private
            $table->text('terms')->nullable();

            // Source + anti-loop
            $table->enum('source', ['kanvas', 'adm_cloud', 'stripe', 'quickbooks', 'netsuite', 'xero', 'manual'])->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->enum('origin', ['external', 'kanvas'])->default('kanvas');

            // Document links
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->unsignedBigInteger('parent_invoice_id')->nullable();   // set on credit_notes

            $table->json('metadata')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            // Uniqueness (Round-4 #3) — dual-constraint pattern
            $table->unique(
                ['apps_id', 'companies_id', 'document_type', 'invoice_number'],
                'invoices_app_company_type_number_uq'
            );
            $table->unique(
                ['apps_id', 'source', 'external_id'],
                'invoices_app_source_external_uq'
            );

            $table->index(['apps_id', 'companies_id', 'document_status', 'due_date'], 'invoices_app_company_status_due_idx');
            $table->index(['apps_id', 'companies_id', 'billable_type', 'billable_id'], 'invoices_app_company_billable_idx');
            $table->index(['apps_id', 'companies_id', 'parent_invoice_id'], 'invoices_app_company_parent_idx');
            $table->index(['apps_id', 'companies_id', 'delivery_status'], 'invoices_app_company_delivery_idx');
            $table->index(['uuid'], 'invoices_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('invoices');
    }
};
