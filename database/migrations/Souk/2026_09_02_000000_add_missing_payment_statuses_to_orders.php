<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * `orders.payment_status` is written from `PaymentStatusEnum`, but the column's ENUM was missing
     * half of its cases — a computed `pending` blew up with "Data truncated for column payment_status".
     * New members are appended to the end of the list so existing ordinals are untouched and MySQL can
     * do this in place.
     */
    public function up(): void
    {
        DB::connection('commerce')->statement(
            "ALTER TABLE orders MODIFY payment_status ENUM(
                'unpaid',
                'pending_authorization',
                'processing',
                'paid',
                'failed',
                'refunded',
                'pending',
                'waiting_device_data',
                'authorized',
                'cancelled'
            ) NULL"
        );
    }

    public function down(): void
    {
        // Lossy on purpose: without collapsing the new members first, MySQL truncates any row holding
        // one of them and the ALTER aborts, leaving the rollback stuck.
        DB::connection('commerce')->update(
            "UPDATE orders SET payment_status = 'unpaid'
             WHERE payment_status IN ('pending', 'waiting_device_data', 'authorized', 'cancelled')"
        );

        DB::connection('commerce')->statement(
            "ALTER TABLE orders MODIFY payment_status ENUM(
                'unpaid',
                'pending_authorization',
                'processing',
                'paid',
                'failed',
                'refunded'
            ) NULL"
        );
    }
};
