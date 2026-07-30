<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'acumatica' to payments.source so a payment synced from Acumatica can be tagged as such — the
 * echo-loop guard in PushPaymentToAcumaticaAction keys on source='acumatica' (mirrors bills.source),
 * and a future inbound payment sync needs to record it.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::connection('accounting')->statement(
            "ALTER TABLE payments MODIFY source ENUM('kanvas','adm_cloud','mercury','stripe_billing','manual','acumatica') NOT NULL DEFAULT 'kanvas'"
        );
    }

    public function down(): void
    {
        DB::connection('accounting')->statement(
            "ALTER TABLE payments MODIFY source ENUM('kanvas','adm_cloud','mercury','stripe_billing','manual') NOT NULL DEFAULT 'kanvas'"
        );
    }
};
