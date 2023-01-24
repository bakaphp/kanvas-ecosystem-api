<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products_variants_warehouse_price_history', function (Blueprint $table) {
            $table->primary(['products_variants_id', 'channels_id', 'warehouses_id'], 'products_variants_price_history_primary');
            $table->bigInteger('products_variants_id')->unsigned();
            $table->bigInteger('channels_id')->unsigned();
            $table->bigInteger('warehouses_id')->unsigned();
            $table->float('price', 10, 2);
            $table->dateTime('from_date');
            $table->timestamp('created_at')->useCurrent();
            $table->index('price');
            $table->foreign('products_variants_id', 'products_variants_ref')->references('id')->on('products_variants');
            $table->foreign('channels_id', 'channels_variants_ref')->references('id')->on('channels');
            $table->foreign('warehouses_id', 'warehouse_channel_ref')->references('id')->on('warehouses');
            $table->index('created_at');
            $table->index('from_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products_variants_warehouse_price_history');
    }
};
