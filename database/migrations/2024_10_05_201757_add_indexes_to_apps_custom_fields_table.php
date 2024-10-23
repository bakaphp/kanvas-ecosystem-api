<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('apps_custom_fields', function (Blueprint $table) {
            $table->index(['companies_id', 'model_name', 'name', DB::raw('value(191)'), 'is_deleted'], 'idx_company_model_name_value_is_deleted');
            $table->index(['entity_id', 'model_name', 'is_deleted', 'companies_id'], 'idx_entity_model_deleted_company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apps_custom_fields', function (Blueprint $table) {
            $table->dropIndex('idx_company_model_name_value_is_deleted');
            $table->dropIndex('idx_entity_model_deleted_company');
        });
    }
};
