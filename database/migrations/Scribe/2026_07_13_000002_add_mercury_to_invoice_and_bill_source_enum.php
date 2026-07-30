<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Register `mercury` as a valid document source on invoices + bills.
 *
 * The bank feed auto-drafts a Bill when it sees money leaving the account with no matching bill on the
 * books (plan §6.1), and can settle invoices it matches. Both need the source value to survive the enum.
 *
 * `payments.source` already allows 'mercury' (it shipped in the original create_payments_table migration),
 * so no change is needed there.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE invoices MODIFY source ENUM('
            . "'kanvas','adm_cloud','stripe','quickbooks','netsuite','xero','acumatica','mercury','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );

        DB::connection('accounting')->statement(
            'ALTER TABLE bills MODIFY source ENUM('
            . "'kanvas','adm_cloud','quickbooks','netsuite','xero','acumatica','mercury','parsed_pdf','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }

    public function down(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE invoices MODIFY source ENUM('
            . "'kanvas','adm_cloud','stripe','quickbooks','netsuite','xero','acumatica','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );

        DB::connection('accounting')->statement(
            'ALTER TABLE bills MODIFY source ENUM('
            . "'kanvas','adm_cloud','quickbooks','netsuite','xero','acumatica','parsed_pdf','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }
};
