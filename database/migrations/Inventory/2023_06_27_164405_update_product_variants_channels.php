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
            Schema::disableForeignKeyConstraints();
            $table->dropForeign('products_variants_channels_products_variants_id_foreign');
            $table->dropForeign('products_variants_channels_warehouses_id_foreign');
            $table->dropForeign('products_variants_channels_channels_id_foreign');
            $table->dropPrimary();
            $table->dropColumn(['products_variants_id', 'warehouses_id']);

            Schema::enableForeignKeyConstraints();
        });
        Schema::table('products_variants_channels', function (Blueprint $table) {
            Schema::disableForeignKeyConstraints();

            $table->bigInteger('product_variants_warehouse_id')->unsigned()->after('channels_id');
            $table->foreign('product_variants_warehouse_id')->references('id')->on('products_variants_warehouses');
            $table->foreign('channels_id')->references('id')->on('channels');

            $table->unique(['product_variants_warehouse_id', 'channels_id'], 'variants_warehouse_channel');
            Schema::enableForeignKeyConstraints();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
