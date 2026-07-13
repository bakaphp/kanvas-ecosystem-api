<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GL coding is account + subaccount. Journal-entry lines already carry the account; this adds the
 * second dimension so imported GL (and native postings) are fully coded and departmental reporting
 * / agent coding-suggestions have the segment to work with.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::connection('accounting')->hasColumn('journal_entry_lines', 'subaccount_id')) {
            return;
        }

        Schema::connection('accounting')->table('journal_entry_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('subaccount_id')->nullable()->after('account_id');
            $table->index(['subaccount_id'], 'jel_subaccount_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('accounting')->hasColumn('journal_entry_lines', 'subaccount_id')) {
            return;
        }

        Schema::connection('accounting')->table('journal_entry_lines', function (Blueprint $table): void {
            $table->dropIndex('jel_subaccount_idx');
            $table->dropColumn('subaccount_id');
        });
    }
};
