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
        Schema::create('products_variants_warehouse_channel_price_history', function (Blueprint $table) {
            $table->bigInteger('products_variants_id')->unsigned();
            $table->bigInteger('channels_id')->unsigned();
            $table->bigInteger('product_variants_warehouse_id')->unsigned();
            $table->float('price', 10, 2);
            $table->dateTime('from_date');
            $table->timestamp('created_at')->useCurrent();
            $table->boolean('is_deleted')->default(0);
            $table->foreign('products_variants_id', 'variants_ref')->references('id')->on('products_variants');
            $table->foreign('channels_id', 'channels_ref')->references('id')->on('channels');
            $table->foreign('product_variants_warehouse_id', 'variant_warehouse_ref')->references('id')->on('products_variants_warehouses');
        });

        Schema::dropIfExists('products_variants_warehouse_price_history');

        Schema::create('products_variants_warehouses_price_history', function (Blueprint $table) {
            $table->bigInteger('product_variants_warehouse_id')->unsigned();
            $table->float('price', 10, 2);
            $table->dateTime('from_date');
            $table->timestamp('created_at')->useCurrent();
            $table->boolean('is_deleted')->default(0);
            $table->foreign('product_variants_warehouse_id', 'variant_warehouse_ref')->references('id')->on('products_variants_warehouses');
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
