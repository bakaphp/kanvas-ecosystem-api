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
            $table->bigInteger('products_variants_id')->after('product_variants_warehouse_id')->unsigned();
            $table->bigInteger('warehouses_id')->after('products_variants_id')->unsigned();
            $table->foreign('products_variants_id')->references('id')->on('products_variants');
            $table->foreign('warehouses_id')->references('id')->on('warehouses');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products_variants_channels', function (Blueprint $table) {
            Schema::disableForeignKeyConstraints();
            $table->dropForeign('products_variants_channels_products_variants_id_foreign');
            $table->dropForeign('products_variants_channels_warehouses_id_foreign');
            $table->dropColumn(['products_variants_id', 'warehouses_id']);

            Schema::enableForeignKeyConstraints();
        });
    }
};
