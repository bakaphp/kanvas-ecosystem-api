<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The allocation tables carry their own `source` enum, separate from the one on invoices/bills — so adding
 * 'mercury' there was not enough. A bank-feed match writes the allocation with source='mercury', and MySQL
 * silently truncates an out-of-enum value to '' rather than refusing it, which would have left allocations
 * with a blank provenance.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE bill_payment_allocations MODIFY source ENUM('
            . "'kanvas','adm_cloud','mercury','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );

        DB::connection('accounting')->statement(
            'ALTER TABLE invoice_payment_allocations MODIFY source ENUM('
            . "'kanvas','stripe','adm_cloud','mercury','manual','wallet'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }

    public function down(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE bill_payment_allocations MODIFY source ENUM('
            . "'kanvas','adm_cloud','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );

        DB::connection('accounting')->statement(
            'ALTER TABLE invoice_payment_allocations MODIFY source ENUM('
            . "'kanvas','stripe','adm_cloud','manual','wallet'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }
};
