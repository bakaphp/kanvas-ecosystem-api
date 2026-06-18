<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal bank_accounts table — just enough so expense reimbursement / sales receipts can debit/credit
 * a real bank account row. Full Banking subdomain (transactions, reconciliations, transfers, statement
 * matching) lands in Phase 3 per plan §6.0.
 *
 * @see plan §5 accounting.bank_accounts schema
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('account_name', 191);
            $table->string('account_number_last4', 4)->nullable();
            $table->string('routing_number_masked', 32)->nullable();
            $table->string('institution_name', 191)->nullable();
            $table->string('currency', 3);

            // The Cash GL account this bank account posts to. JE composers credit/debit this account when
            // money moves in/out of the bank.
            $table->unsignedBigInteger('gl_account_id');

            // Balance snapshot — populated by connector polling (Mercury / Plaid / etc.) when available.
            // For PR 5 Cut B, these stay null and can be filled manually or via future ingest.
            $table->decimal('current_balance_native', 18, 4)->nullable();
            $table->decimal('available_balance_native', 18, 4)->nullable();
            $table->timestamp('last_balance_sync_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->string('source', 32)->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'source', 'external_id'], 'ba_app_source_external_uq');
            $table->index(['apps_id', 'companies_id', 'is_active'], 'ba_app_company_active_idx');
            $table->index(['gl_account_id'], 'ba_gl_account_idx');
            $table->index(['uuid'], 'ba_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('bank_accounts');
    }
};
