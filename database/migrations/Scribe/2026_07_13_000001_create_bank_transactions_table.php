<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw bank feed. One row per movement a bank reports (Mercury today, any other feed later).
 *
 * The feed is the source of cash truth, so a row lands here BEFORE we know what it settles —
 * `match_status` starts `unmatched` and the matcher resolves it afterwards. Matched rows point at the
 * settled document and reuse ITS journal entry; only genuinely unmatched cash gets its own JE composed
 * from `category` (bank fee / interest / suspense).
 *
 * `category` is not in plan §5 — added here so the JE composer can route fee/interest away from Suspense
 * without re-parsing `raw_payload`. Connectors map their own txn kinds onto it.
 *
 * @see docs/accounting/mercury-connector-plan.md §5 + §6.1
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->unsignedBigInteger('bank_account_id');

            $table->timestamp('posted_at');
            $table->date('transaction_date');
            $table->enum('direction', ['debit', 'credit']);

            $table->decimal('amount_native', 18, 4);
            $table->string('currency', 3);
            $table->decimal('amount_base', 18, 4);
            $table->decimal('fx_rate_to_base', 20, 10)->default(1);

            $table->string('counterparty_name', 191)->nullable();
            $table->string('counterparty_account_last4', 4)->nullable();
            $table->text('memo')->nullable();

            // How the bank classified the movement. Drives JE composition when the txn stays unmatched.
            $table->enum('category', ['bank_fee', 'interest_income', 'transfer', 'unknown'])->default('unknown');

            $table->json('raw_payload')->nullable();

            $table->enum('match_status', ['unmatched', 'auto_matched', 'manually_matched', 'ignored'])
                ->default('unmatched');
            $table->enum('matched_to_type', [
                'invoice_payment',
                'bill_payment',
                'expense',
                'transfer',
                'sales_receipt',
            ])->nullable();
            $table->unsignedBigInteger('matched_to_id')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->enum('matched_by', ['system', 'agent', 'human'])->nullable();
            $table->decimal('match_confidence', 5, 4)->nullable();

            // Set when this row generated (or reused) a JE. Suspense postings land here too.
            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->string('source', 32)->default('kanvas');
            $table->string('external_id', 191)->nullable();

            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            // Idempotent re-poll: webhook + cron can both deliver the same txn.
            $table->unique(['apps_id', 'source', 'external_id'], 'bt_app_source_external_uq');
            $table->index(['apps_id', 'companies_id', 'bank_account_id', 'posted_at'], 'bt_app_company_account_posted_idx');
            $table->index(['apps_id', 'companies_id', 'match_status'], 'bt_app_company_match_idx');
            $table->index(['journal_entry_id'], 'bt_journal_entry_idx');
            $table->index(['uuid'], 'bt_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('bank_transactions');
    }
};
