<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * A company can live in several apps, so company + integration + region never identified one
     * integration. The column stays nullable: rows written before it existed have no recorded owner
     * and MySQL lets any number of NULLs coexist under the unique index, so they are untouched until
     * an owner is established for each.
     */
    public function up(): void
    {
        Schema::table('integration_companies', function (Blueprint $table) {
            $table->unsignedBigInteger('apps_id')->nullable()->after('id');
            $table->unique(
                ['apps_id', 'companies_id', 'integrations_id', 'region_id'],
                'integration_companies_app_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('integration_companies', function (Blueprint $table) {
            $table->dropUnique('integration_companies_app_identity_unique');
            $table->dropColumn('apps_id');
        });
    }
};
