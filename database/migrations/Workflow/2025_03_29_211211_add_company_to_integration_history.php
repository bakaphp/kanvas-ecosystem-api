<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('entity_integration_history', function (Blueprint $table) {
            $table->unsignedBigInteger('companies_id')->after('integrations_company_id')->nullable()->index();
            $table->index(['companies_id', 'apps_id', 'is_deleted'], 'companies_entity_namespace_entity_id_index');
            $table->index(['companies_id', 'apps_id', 'entity_id', 'is_deleted'], 'companies_entity_namespace_entity_id_index_v2');
            $table->index(['created_at']);
            $table->index(['updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entity_integration_history', function (Blueprint $table) {
            $table->dropColumn('companies_id');
            $table->dropIndex('companies_entity_namespace_entity_id_index');
            $table->dropIndex('companies_entity_namespace_entity_id_index_v2');
            $table->dropIndex('created_at');
            $table->dropIndex('updated_at');
        });
    }
};
