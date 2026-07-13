<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Register `acumatica` as a valid document source on invoices + bills so the Acumatica connector
 * can land externally-issued AR/AP documents (origin=EXTERNAL, source='acumatica') without the
 * enum truncating the value. Mirrors the existing quickbooks/netsuite/xero ERP sources.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::connection('accounting')->statement(
            "ALTER TABLE invoices MODIFY source ENUM("
            . "'kanvas','adm_cloud','stripe','quickbooks','netsuite','xero','acumatica','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );

        DB::connection('accounting')->statement(
            "ALTER TABLE bills MODIFY source ENUM("
            . "'kanvas','adm_cloud','quickbooks','netsuite','xero','acumatica','parsed_pdf','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }

    public function down(): void
    {
        DB::connection('accounting')->statement(
            "ALTER TABLE invoices MODIFY source ENUM("
            . "'kanvas','adm_cloud','stripe','quickbooks','netsuite','xero','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );

        DB::connection('accounting')->statement(
            "ALTER TABLE bills MODIFY source ENUM("
            . "'kanvas','adm_cloud','quickbooks','netsuite','xero','parsed_pdf','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }
};
