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
        Schema::table('products', function (Blueprint $table) {
            $table->index('weight');
            $table->index(['apps_id', 'companies_id', 'is_deleted']);
            $table->index(['apps_id', 'companies_id', 'is_published', 'is_deleted'], 'products_published_idx');
        });

        Schema::table('products_variants', function (Blueprint $table) {
            $table->index('weight');
            $table->index(['apps_id', 'companies_id', 'is_deleted']);
            $table->index(['apps_id', 'companies_id', 'products_id', 'is_published'], 'prod_variants_pub_idx');
        });

        Schema::table('products_types_attributes', function (Blueprint $table) {
            $table->index('is_deleted');
            $table->index('updated_at');
            $table->index('created_at');
            $table->index('to_variant');
            $table->index(['products_types_id', 'attributes_id', 'to_variant', 'is_deleted'], 'pta_attr_idx');
            $table->index(['products_types_id', 'to_variant', 'is_deleted'], 'pta_variant_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['weight']);
            $table->dropIndex(['apps_id', 'companies_id', 'is_deleted']);
            $table->dropIndex('products_published_idx');
        });

        Schema::table('products_variants', function (Blueprint $table) {
            $table->dropIndex(['weight']);
            $table->dropIndex(['apps_id', 'companies_id', 'is_deleted']);
            $table->dropIndex('prod_variants_pub_idx');
        });

        Schema::table('products_types_attributes', function (Blueprint $table) {
            $table->dropIndex(['is_deleted']);
            $table->dropIndex(['updated_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['to_variant']);
            $table->dropIndex('pta_attr_idx');
            $table->dropIndex('pta_variant_idx');
        });
    }
};