<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bank statement as it was handed to us (CSV, PDF or webhook rows). Each landed bank_transaction points
 * back at the statement that first delivered it, so coverage per account is the latest posted period_end
 * and a gap between two statements is visible.
 *
 * A rejected statement keeps its row (status = rejected + reason) and lands no transactions: the agent
 * needs the reason to ask for a clean file, and the audit needs to show the file was refused.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedInteger('users_id')->nullable();
            $table->unsignedBigInteger('bank_account_id')->nullable();

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('opening_balance', 18, 4)->nullable();
            $table->decimal('closing_balance', 18, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('account_number_last4', 4)->nullable();

            $table->unsignedBigInteger('filesystem_id')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('new_row_count')->default(0);

            $table->string('status', 16);
            $table->string('source', 32);
            $table->string('rejected_reason_code', 64)->nullable();
            $table->text('rejected_reason')->nullable();
            $table->char('fingerprint', 40)->nullable();

            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'bank_account_id', 'status', 'period_end'], 'bs_account_coverage_idx');
            $table->index(['apps_id', 'companies_id', 'fingerprint'], 'bs_fingerprint_idx');
            $table->index(['uuid'], 'bs_uuid_idx');
        });

        Schema::connection('accounting')->table('bank_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_statement_id')->nullable()->after('bank_account_id');
            $table->index(['bank_statement_id'], 'bt_bank_statement_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('bank_transactions', function (Blueprint $table) {
            $table->dropIndex('bt_bank_statement_idx');
            $table->dropColumn('bank_statement_id');
        });

        Schema::connection('accounting')->dropIfExists('bank_statements');
    }
};
