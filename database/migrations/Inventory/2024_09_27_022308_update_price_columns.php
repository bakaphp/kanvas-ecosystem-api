<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
        * Run the migrations.
        *
        * @return void
        */
    public function up()
    {
        Schema::table('products_variants_warehouse_channel_price_history', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
        });

        Schema::table('products_variants_warehouses_price_history', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
        });

        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
            $table->decimal('discounted_price', 15, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products_variants_warehouse_channel_price_history', function (Blueprint $table) {
            $table->float('price', 10, 2)->change();
        });

        Schema::table('products_variants_warehouses_price_history', function (Blueprint $table) {
            $table->float('price', 10, 2)->change();
        });

        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->float('price', 10, 2)->change();
            $table->float('discounted_price', 10, 2)->change();
        });
    }
};
