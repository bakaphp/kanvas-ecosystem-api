<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a credit-note line override the GL account it debits (e.g. a "Control Acct#" like Promotion
 * Discount or MDF, from a back-end-rebate request form) instead of always hitting Service Revenue.
 * Null on every existing/normal invoice line — behavior there is unchanged.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::connection('accounting')->hasColumn('invoice_lines', 'account_id')) {
            return;
        }

        Schema::connection('accounting')->table('invoice_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id')->nullable()->after('item_id');
            $table->index(['account_id'], 'invoice_lines_account_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('accounting')->hasColumn('invoice_lines', 'account_id')) {
            return;
        }

        Schema::connection('accounting')->table('invoice_lines', function (Blueprint $table): void {
            $table->dropIndex('invoice_lines_account_idx');
            $table->dropColumn('account_id');
        });
    }
};
