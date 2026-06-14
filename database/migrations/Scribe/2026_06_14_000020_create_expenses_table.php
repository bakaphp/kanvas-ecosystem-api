<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('expense_number', 64)->nullable();

            // Vendor — polymorphic (Organization|People). Nullable for one-off cash purchases.
            $table->string('vendor_billable_type', 64)->nullable();
            $table->unsignedBigInteger('vendor_billable_id')->nullable();

            // Vendor snapshot (frozen on approval)
            $table->string('vendor_display_name', 191)->nullable();
            $table->string('vendor_legal_name', 191)->nullable();
            $table->string('vendor_tax_id', 64)->nullable();
            $table->string('vendor_email', 191)->nullable();

            // Lifecycle
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected', 'voided'])->default('draft');
            $table->date('expense_date');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('approved_by_users_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedInteger('rejected_by_users_id')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason_code', 64)->nullable();

            // Who paid (drives the JE shape — see ExpenseJournalEntryComposer)
            $table->enum('paid_by', [
                'company_card',
                'company_cash',
                'employee_personal',
                'company_bank_transfer',
            ]);
            $table->unsignedInteger('paid_by_users_id')->nullable();   // set when paid_by='employee_personal'
            $table->unsignedBigInteger('payment_method_id')->nullable();  // souk.payment_methods.id (cross-DB)
            $table->unsignedBigInteger('bank_account_id')->nullable();    // accounting.bank_accounts.id (when paid_by='company_bank_transfer')

            // Reimbursement state (only meaningful when paid_by='employee_personal')
            $table->enum('reimbursement_status', ['not_applicable', 'pending', 'approved', 'paid'])->default('not_applicable');
            $table->unsignedBigInteger('reimbursement_payment_id')->nullable();  // souk.payments.id when reimbursed
            $table->timestamp('reimbursed_at')->nullable();

            // Currency + FX
            $table->string('currency', 3);
            $table->decimal('fx_rate_to_base', 20, 10);
            $table->timestamp('fx_rate_at')->nullable();

            // Native amounts
            $table->decimal('subtotal_native', 18, 4)->default(0);
            $table->decimal('tax_native', 18, 4)->default(0);
            $table->decimal('total_native', 18, 4)->default(0);

            // Base amounts
            $table->decimal('subtotal_base', 18, 4)->default(0);
            $table->decimal('tax_base', 18, 4)->default(0);
            $table->decimal('total_base', 18, 4)->default(0);

            $table->json('tax_metadata')->nullable();
            $table->json('regional_compliance')->nullable();

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            // Source + anti-loop
            $table->enum('source', ['kanvas', 'adm_cloud', 'quickbooks', 'netsuite', 'xero', 'parsed_pdf', 'manual'])->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->enum('origin', ['external', 'kanvas'])->default('kanvas');

            $table->json('metadata')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();   // creator (NOT necessarily paid_by user)
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'expense_number'], 'exp_app_company_number_uq');
            $table->unique(['apps_id', 'source', 'external_id'], 'exp_app_source_external_uq');

            $table->index(['apps_id', 'companies_id', 'status', 'expense_date'], 'exp_app_company_status_date_idx');
            $table->index(['apps_id', 'companies_id', 'paid_by', 'paid_by_users_id', 'reimbursement_status'], 'exp_app_company_paidby_reimb_idx');
            $table->index(['vendor_billable_type', 'vendor_billable_id'], 'exp_vendor_billable_idx');
            $table->index(['bank_account_id'], 'exp_bank_account_idx');
            $table->index(['uuid'], 'exp_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('expenses');
    }
};
