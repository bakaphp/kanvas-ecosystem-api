<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->decimal('debit_native',  18, 4)->default(0);
            $table->decimal('credit_native', 18, 4)->default(0);
            $table->decimal('debit_base',    18, 4)->default(0);
            $table->decimal('credit_base',   18, 4)->default(0);

            $table->string('currency', 3);
            $table->decimal('fx_rate_to_base', 20, 10);

            // Polymorphic dimensional tags
            $table->string('customer_billable_type', 64)->nullable();
            $table->unsignedBigInteger('customer_billable_id')->nullable();
            $table->string('vendor_billable_type', 64)->nullable();
            $table->unsignedBigInteger('vendor_billable_id')->nullable();

            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('class_id')->nullable();          // dimension reserved; UI defers to phase 4
            $table->unsignedBigInteger('department_id')->nullable();     // dimension reserved

            $table->text('memo')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'journal_entry_id'], 'jel_account_je_idx');
            $table->index(['journal_entry_id'], 'jel_je_idx');
            $table->index(['customer_billable_type', 'customer_billable_id'], 'jel_customer_billable_idx');
            $table->index(['vendor_billable_type', 'vendor_billable_id'], 'jel_vendor_billable_idx');
            $table->index(['item_id'], 'jel_item_idx');
        });

        // Per-line invariant: exactly one of (debit_native, credit_native) is non-zero.
        // Application-level enforcement happens in JournalEntryValidator; this is belt-and-suspenders at the DB level.
        // The balanced-JE invariant (SUM(debit_base) == SUM(credit_base) per journal_entry_id) cannot be expressed as a
        // simple CHECK and is enforced in JournalEntryValidator only.
        DB::connection('accounting')->statement(
            'ALTER TABLE journal_entry_lines ADD CONSTRAINT jel_one_sided_chk '
            . 'CHECK ('
            . '    (debit_native = 0 AND credit_native >= 0) '
            . ' OR (credit_native = 0 AND debit_native >= 0)'
            . ')'
        );

        DB::connection('accounting')->statement(
            'ALTER TABLE journal_entry_lines ADD CONSTRAINT jel_nonnegative_chk '
            . 'CHECK (debit_native >= 0 AND credit_native >= 0 AND debit_base >= 0 AND credit_base >= 0)'
        );
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('journal_entry_lines');
    }
};
