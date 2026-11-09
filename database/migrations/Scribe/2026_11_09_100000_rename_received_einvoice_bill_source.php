<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A vendor's electronic invoice lands as a bill with source = 'e_invoice' in every country; the country is on its
 * received fiscal row. 'dgii_ecf' named one authority, so a second country would have needed an enum change.
 */
return new class () extends Migration {
    private const string WITH_BOTH = "'kanvas','adm_cloud','quickbooks','netsuite','xero','acumatica','mercury','parsed_pdf','dgii_ecf','e_invoice','manual'";

    public function up(): void
    {
        $db = DB::connection('accounting');

        $db->statement('ALTER TABLE bills MODIFY source ENUM(' . self::WITH_BOTH . ") NOT NULL DEFAULT 'kanvas'");
        $db->table('bills')->where('source', 'dgii_ecf')->update(['source' => 'e_invoice']);
        $db->statement(
            'ALTER TABLE bills MODIFY source ENUM('
            . "'kanvas','adm_cloud','quickbooks','netsuite','xero','acumatica','mercury','parsed_pdf','e_invoice','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }

    public function down(): void
    {
        $db = DB::connection('accounting');

        $db->statement('ALTER TABLE bills MODIFY source ENUM(' . self::WITH_BOTH . ") NOT NULL DEFAULT 'kanvas'");
        $db->table('bills')->where('source', 'e_invoice')->update(['source' => 'dgii_ecf']);
        $db->statement(
            'ALTER TABLE bills MODIFY source ENUM('
            . "'kanvas','adm_cloud','quickbooks','netsuite','xero','acumatica','mercury','parsed_pdf','dgii_ecf','manual'"
            . ") NOT NULL DEFAULT 'kanvas'"
        );
    }
};
