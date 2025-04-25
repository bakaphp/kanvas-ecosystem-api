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
        Schema::table('products_variants', function (Blueprint $table) {
            $table->boolean('is_published')->default(1)->after('rating')->index('idx_is_published');
            $table->index(['is_published', 'products_id'], 'idx_is_published_products_id');
            $table->index(['is_published', 'companies_id'], 'idx_is_published_companies_id');
            $table->index(['is_published', 'apps_id'], 'idx_is_published_apps_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products_variants', function (Blueprint $table) {
            $table->dropIndex('idx_is_published');
            $table->dropIndex('idx_is_published_products_id');
            $table->dropIndex('idx_is_published_companies_id');
            $table->dropIndex('idx_is_published_apps_id');
            $table->dropColumn('is_published');
        });
    }
};
