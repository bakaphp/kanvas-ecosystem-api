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
        Schema::table('discounts', function (Blueprint $table) {
            // Drop the existing unique constraint on code
            $table->dropUnique(['code']);

            // Add new composite unique constraint
            $table->unique(['code', 'companies_id', 'apps_id'], 'discounts_code_company_app_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            // Drop the composite unique constraint
            $table->dropUnique('discounts_code_company_app_unique');

            // Restore the original unique constraint on code only
            $table->unique('code');
        });
    }
};
