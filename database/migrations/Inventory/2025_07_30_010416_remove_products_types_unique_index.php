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
        Schema::table('products_types', function (Blueprint $table) {
            $table->dropUnique(['apps_id', 'companies_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products_types', function (Blueprint $table) {
            $table->unique(['apps_id', 'companies_id', 'slug']);
        });
    }
};
