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
        Schema::table('leads_types', function (Blueprint $table) {
            $table->tinyInteger('is_active')->default(1)->after('description');
            $table->index('is_active');
            $table->index(['apps_id', 'companies_id', 'is_active', 'is_deleted']);
        });

        Schema::table('leads_sources', function (Blueprint $table) {
            $table->tinyInteger('is_active')->default(1)->after('description');
            $table->index('is_active');
            $table->index(['apps_id', 'companies_id', 'is_active', 'is_deleted']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
