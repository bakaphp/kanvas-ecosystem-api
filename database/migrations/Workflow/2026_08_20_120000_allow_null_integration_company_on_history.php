<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `executeIntegration` bails before it ever resolves an `integration_companies` row when the tenant
 * has no default region, or none wired for that region — the two cases where a workflow silently
 * stops appearing in the integration history at all. Recording those as FAILED needs the column to
 * accept NULL; the foreign key stays, since NULL satisfies it.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        DB::connection('workflow')->statement(
            'ALTER TABLE `entity_integration_history` MODIFY `integrations_company_id` BIGINT UNSIGNED NULL'
        );
    }

    public function down(): void
    {
        DB::connection('workflow')
            ->table('entity_integration_history')
            ->whereNull('integrations_company_id')
            ->delete();

        DB::connection('workflow')->statement(
            'ALTER TABLE `entity_integration_history` MODIFY `integrations_company_id` BIGINT UNSIGNED NOT NULL'
        );
    }
};
