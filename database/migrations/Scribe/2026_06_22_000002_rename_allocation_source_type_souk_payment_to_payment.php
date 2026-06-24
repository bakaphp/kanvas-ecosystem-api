<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        // Allocations no longer reference Souk.Payments — they FK accounting.payments (Scribe.Payment).
        // Rename the enum case to match. Two-phase ALTER: widen → UPDATE rows → narrow.
        DB::connection('accounting')->statement(
            "ALTER TABLE invoice_payment_allocations
             MODIFY COLUMN source_type ENUM('souk_payment','payment','credit_note','prepayment','overpayment','wallet','manual')
             NOT NULL DEFAULT 'payment'"
        );
        DB::connection('accounting')->statement(
            "ALTER TABLE bill_payment_allocations
             MODIFY COLUMN source_type ENUM('souk_payment','payment','credit_note','prepayment','overpayment','wallet','manual')
             NOT NULL DEFAULT 'payment'"
        );

        DB::connection('accounting')->update(
            "UPDATE invoice_payment_allocations SET source_type = 'payment' WHERE source_type = 'souk_payment'"
        );
        DB::connection('accounting')->update(
            "UPDATE bill_payment_allocations SET source_type = 'payment' WHERE source_type = 'souk_payment'"
        );

        DB::connection('accounting')->statement(
            "ALTER TABLE invoice_payment_allocations
             MODIFY COLUMN source_type ENUM('payment','credit_note','prepayment','overpayment','wallet','manual')
             NOT NULL DEFAULT 'payment'"
        );
        DB::connection('accounting')->statement(
            "ALTER TABLE bill_payment_allocations
             MODIFY COLUMN source_type ENUM('payment','credit_note','prepayment','overpayment','wallet','manual')
             NOT NULL DEFAULT 'payment'"
        );
    }

    public function down(): void
    {
        DB::connection('accounting')->statement(
            "ALTER TABLE invoice_payment_allocations
             MODIFY COLUMN source_type ENUM('souk_payment','payment','credit_note','prepayment','overpayment','wallet','manual')
             NOT NULL DEFAULT 'souk_payment'"
        );
        DB::connection('accounting')->statement(
            "ALTER TABLE bill_payment_allocations
             MODIFY COLUMN source_type ENUM('souk_payment','payment','credit_note','prepayment','overpayment','wallet','manual')
             NOT NULL DEFAULT 'souk_payment'"
        );

        DB::connection('accounting')->update(
            "UPDATE invoice_payment_allocations SET source_type = 'souk_payment' WHERE source_type = 'payment'"
        );
        DB::connection('accounting')->update(
            "UPDATE bill_payment_allocations SET source_type = 'souk_payment' WHERE source_type = 'payment'"
        );

        DB::connection('accounting')->statement(
            "ALTER TABLE invoice_payment_allocations
             MODIFY COLUMN source_type ENUM('souk_payment','credit_note','prepayment','overpayment','wallet','manual')
             NOT NULL DEFAULT 'souk_payment'"
        );
        DB::connection('accounting')->statement(
            "ALTER TABLE bill_payment_allocations
             MODIFY COLUMN source_type ENUM('souk_payment','credit_note','prepayment','overpayment','wallet','manual')
             NOT NULL DEFAULT 'souk_payment'"
        );
    }
};
