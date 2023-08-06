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
        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->index(['products_variants_id', 'channels_id'], 'products_variants_channels_index');
            $table->index(['products_variants_id', 'channels_id', 'is_deleted', 'is_published'], 'products_variants_published_channels_index');
            $table->index(['channels_id', 'is_deleted', 'is_published'], 'published_channels_index');
            $table->index(['product_variants_warehouse_id', 'channels_id', 'is_deleted', 'is_published'], 'products_variants_warehouse_published_channels_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
