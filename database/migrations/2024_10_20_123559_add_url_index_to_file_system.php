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
        Schema::table('filesystem', function (Blueprint $table) {
            // Adding a composite index on apps_id, companies_id, and url
            $table->index(['apps_id', 'companies_id', 'url'], 'idx_apps_companies_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('filesystem', function (Blueprint $table) {
            // Dropping the composite index
            $table->dropIndex('idx_apps_companies_url');
        });
    }
};
