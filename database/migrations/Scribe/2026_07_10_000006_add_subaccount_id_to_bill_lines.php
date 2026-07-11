<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bill line GL coding is account + subaccount (same as JE lines). This adds the subaccount so the
 * AP-bill agent can code a full dimension and PushBillToAcumaticaAction can translate it back to the
 * ERP's Subaccount field.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::connection('accounting')->hasColumn('bill_lines', 'subaccount_id')) {
            return;
        }

        Schema::connection('accounting')->table('bill_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('subaccount_id')->nullable()->after('expense_account_id');
            $table->index(['subaccount_id'], 'bill_lines_subaccount_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('accounting')->hasColumn('bill_lines', 'subaccount_id')) {
            return;
        }

        Schema::connection('accounting')->table('bill_lines', function (Blueprint $table): void {
            $table->dropIndex('bill_lines_subaccount_idx');
            $table->dropColumn('subaccount_id');
        });
    }
};
