<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A statement row that settles a bill or invoice writes its Payment and allocation with
 * source = 'bank_statement', and a payment-provider webhook records a bill payment with
 * source = 'payment_provider'. MySQL truncates an out-of-enum value to '' instead of refusing it, so
 * without these the provenance would silently go blank.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE payments MODIFY source ENUM('
            . "'kanvas','adm_cloud','mercury','stripe_billing','manual','acumatica','bank_statement','payment_provider'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );

        DB::connection('accounting')->statement(
            'ALTER TABLE bill_payment_allocations MODIFY source ENUM('
            . "'kanvas','adm_cloud','mercury','manual','bank_statement','payment_provider'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );

        DB::connection('accounting')->statement(
            'ALTER TABLE invoice_payment_allocations MODIFY source ENUM('
            . "'kanvas','stripe','adm_cloud','mercury','manual','wallet','bank_statement','payment_provider'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }

    public function down(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE payments MODIFY source ENUM('
            . "'kanvas','adm_cloud','mercury','stripe_billing','manual','acumatica'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );

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
};
