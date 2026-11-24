<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Column only; the value is copied from the tax_id / rnc / ein custom fields by
 * `guild:backfill-organization-tax-id`, outside the deploy.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            $table->string('tax_id', 32)->nullable()->default(null)->after('name');
            $table->string('tax_id_type', 16)->nullable()->default(null)->after('tax_id');
            $table->index(['apps_id', 'companies_id', 'tax_id'], 'ix_organizations_tax_id');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('organizations', function (Blueprint $table) {
            $table->dropIndex('ix_organizations_tax_id');
            $table->dropColumn(['tax_id', 'tax_id_type']);
        });
    }
};
