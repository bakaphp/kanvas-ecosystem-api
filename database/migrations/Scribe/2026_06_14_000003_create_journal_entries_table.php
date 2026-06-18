<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('je_number', 64)->nullable();
            $table->timestamp('posted_at');
            $table->unsignedBigInteger('fiscal_period_id')->nullable();

            $table->string('source_type', 64);                          // invoice|credit_note|bill|vendor_credit|payment|sales_receipt|expense|bank_transaction|transfer|manual|opening_balance|adjustment
            $table->unsignedBigInteger('source_id')->nullable();        // FK target inside the same accounting DB (sub-ledger table id) — cross-DB sources use source_external_id
            $table->string('source_external_id', 191)->nullable();

            $table->text('memo')->nullable();
            $table->boolean('is_adjustment')->default(false);
            $table->unsignedBigInteger('is_reversal_of')->nullable();   // FK → journal_entries.id

            $table->unsignedInteger('posted_by_users_id')->nullable();  // NULL = system auto-post

            $table->enum('status', ['draft', 'posted', 'reversed'])->default('posted');

            $table->string('source', 32)->default('kanvas');            // kanvas|adm_cloud|stripe|quickbooks|netsuite|xero|manual
            $table->string('external_id', 191)->nullable();
            $table->enum('origin', ['external', 'kanvas'])->default('kanvas');

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'je_number'], 'journal_entries_app_company_number_uq');
            $table->unique(['apps_id', 'source', 'external_id'], 'journal_entries_app_source_external_uq');

            $table->index(['apps_id', 'companies_id', 'posted_at', 'status'], 'journal_entries_app_company_posted_idx');
            $table->index(['apps_id', 'companies_id', 'source_type', 'source_id'], 'journal_entries_app_company_source_idx');
            $table->index(['fiscal_period_id'], 'journal_entries_fiscal_period_idx');
            $table->index(['is_reversal_of'], 'journal_entries_reversal_idx');
            $table->index(['uuid'], 'journal_entries_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('journal_entries');
    }
};
