<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A vendor's e-CF received through the company's e-CF provider lands as a bill with source = 'dgii_ecf'
 * (CreateBillFromReceivedEcfAction). The source names the document, not the provider, so switching from
 * WebPOS to Alanube keeps the (apps_id, source, external_id) dedupe intact.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE bills MODIFY source ENUM('
            . "'kanvas','adm_cloud','quickbooks','netsuite','xero','acumatica','mercury','parsed_pdf','dgii_ecf','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }

    public function down(): void
    {
        DB::connection('accounting')->statement(
            'ALTER TABLE bills MODIFY source ENUM('
            . "'kanvas','adm_cloud','quickbooks','netsuite','xero','acumatica','mercury','parsed_pdf','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }
};
