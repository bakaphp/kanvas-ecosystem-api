<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fields that drive queries and idempotency move out of `metadata` into indexed columns: the suspense
 * reclassification on a bank transaction, and how a bill payment was recorded plus when it was flagged
 * as not confirmed by the bank.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('bank_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('suspense_reclassified_journal_entry_id')->nullable();
            $table->unsignedBigInteger('suspense_reclassified_to_account_id')->nullable();

            $table->index(
                ['apps_id', 'companies_id', 'suspense_reclassified_journal_entry_id'],
                'bt_suspense_reclassified_idx'
            );
        });

        Schema::connection('accounting')->table('payments', function (Blueprint $table): void {
            $table->string('recorded_via', 16)->nullable();
            $table->timestamp('unconfirmed_flagged_at')->nullable();

            $table->index(
                ['apps_id', 'companies_id', 'direction', 'recorded_via', 'unconfirmed_flagged_at'],
                'payments_unconfirmed_idx'
            );
        });

        $db = DB::connection('accounting');
        $db->statement(
            "UPDATE bank_transactions
                SET suspense_reclassified_journal_entry_id = JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.suspense_reclassified_journal_entry_id')),
                    suspense_reclassified_to_account_id = JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.suspense_reclassified_to_account_id'))
              WHERE JSON_EXTRACT(metadata, '$.suspense_reclassified_journal_entry_id') IS NOT NULL"
        );
        $db->statement(
            "UPDATE payments
                SET recorded_via = JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.recorded_via')),
                    unconfirmed_flagged_at = STR_TO_DATE(LEFT(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.unconfirmed_flagged_at')), 19), '%Y-%m-%dT%H:%i:%s')
              WHERE JSON_EXTRACT(metadata, '$.recorded_via') IS NOT NULL"
        );
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_unconfirmed_idx');
            $table->dropColumn(['recorded_via', 'unconfirmed_flagged_at']);
        });

        Schema::connection('accounting')->table('bank_transactions', function (Blueprint $table): void {
            $table->dropIndex('bt_suspense_reclassified_idx');
            $table->dropColumn(['suspense_reclassified_journal_entry_id', 'suspense_reclassified_to_account_id']);
        });
    }
};
