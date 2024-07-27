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
        Schema::table('users_associated_company', function (Blueprint $table) {
            $table->index(['companies_id', 'is_deleted']);
        });

        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->index(['apps_id', 'companies_id']);
        });

        Schema::table('companies_branches', function (Blueprint $table) {
            $table->index('is_deleted');
            $table->index(['companies_id', 'is_deleted']);
            $table->index(['uuid', 'companies_id', 'is_deleted']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_associated_company', function (Blueprint $table) {
            $table->dropIndex(['companies_id', 'is_deleted']);
        });

        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->dropIndex(['apps_id', 'companies_id']);
        });

        Schema::table('companies_branches', function (Blueprint $table) {
            $table->dropIndex(['is_deleted']);
            $table->dropIndex(['companies_id', 'is_deleted']);
            $table->dropIndex(['uuid', 'companies_id', 'is_deleted']);
        });
    }
};
