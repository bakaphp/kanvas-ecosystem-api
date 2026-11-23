<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Stripe payment link paid against an invoice writes its Payment with source = 'stripe' (the session
 * id as external_id, which the unique (apps_id, source, external_id) key makes redelivery-safe).
 * 'stripe_billing' is subscriptions, a different flow. invoice_payment_allocations already allows 'stripe'.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE payments MODIFY source ENUM('
            . "'kanvas','adm_cloud','mercury','stripe_billing','stripe','manual','acumatica','bank_statement','payment_provider'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }

    public function down(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE payments MODIFY source ENUM('
            . "'kanvas','adm_cloud','mercury','stripe_billing','manual','acumatica','bank_statement','payment_provider'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }
};
