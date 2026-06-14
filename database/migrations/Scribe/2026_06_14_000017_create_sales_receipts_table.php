<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('sales_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('receipt_number', 64)->nullable();

            // Polymorphic billable (Organization|People) — same shape as invoices/quotes
            $table->string('billable_type', 64)->nullable();
            $table->unsignedBigInteger('billable_id')->nullable();

            // Billable snapshot (frozen at creation — sales receipt IS the economic event)
            $table->string('billable_display_name', 191)->nullable();
            $table->string('billable_legal_name', 191)->nullable();
            $table->string('billable_tax_id', 64)->nullable();
            $table->string('billable_email', 191)->nullable();
            $table->json('billing_address_snapshot')->nullable();

            // Lifecycle — sales receipts are simpler than invoices (no draft, no AR cycle)
            $table->enum('status', ['recorded', 'voided'])->default('recorded');
            $table->date('receipt_date');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason_code', 64)->nullable();

            // Tax semantics (consistency with invoices)
            $table->enum('tax_calculation_mode', ['exclusive', 'inclusive', 'not_applicable'])->default('exclusive');

            // Currency + FX
            $table->string('currency', 3);
            $table->decimal('fx_rate_to_base', 20, 10);
            $table->timestamp('fx_rate_at')->nullable();

            // Native amounts
            $table->decimal('subtotal_native', 18, 4)->default(0);
            $table->decimal('tax_native', 18, 4)->default(0);
            $table->decimal('discount_native', 18, 4)->default(0);
            $table->decimal('total_native', 18, 4)->default(0);

            // Base amounts
            $table->decimal('subtotal_base', 18, 4)->default(0);
            $table->decimal('tax_base', 18, 4)->default(0);
            $table->decimal('discount_base', 18, 4)->default(0);
            $table->decimal('total_base', 18, 4)->default(0);

            $table->json('tax_metadata')->nullable();
            $table->json('regional_compliance')->nullable();

            // Which Cash GL account received the money. NULL → composer falls back to system Cash account.
            // Will be linked via bank_accounts.gl_account_id once PR 5's banking subdomain lands.
            $table->unsignedBigInteger('cash_account_id')->nullable();

            // Payment linkage (souk.payment_methods + souk.payments — both nullable, both cross-DB)
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();

            $table->text('notes')->nullable();           // customer-facing
            $table->text('internal_notes')->nullable();   // private

            // Source + anti-loop
            $table->enum('source', ['kanvas', 'adm_cloud', 'stripe', 'quickbooks', 'netsuite', 'xero', 'manual'])->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->enum('origin', ['external', 'kanvas'])->default('kanvas');

            $table->json('metadata')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            // Uniqueness
            $table->unique(['apps_id', 'companies_id', 'receipt_number'], 'sr_app_company_number_uq');
            $table->unique(['apps_id', 'source', 'external_id'], 'sr_app_source_external_uq');

            $table->index(['apps_id', 'companies_id', 'status', 'receipt_date'], 'sr_app_company_status_date_idx');
            $table->index(['apps_id', 'companies_id', 'billable_type', 'billable_id'], 'sr_app_company_billable_idx');
            $table->index(['cash_account_id'], 'sr_cash_account_idx');
            $table->index(['payment_id'], 'sr_payment_idx');
            $table->index(['uuid'], 'sr_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('sales_receipts');
    }
};
