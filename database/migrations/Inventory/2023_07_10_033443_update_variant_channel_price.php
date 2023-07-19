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
        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->double('price', 8, 2)->default(0.00)->change();
        });

        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->double('price', 8, 2)->default(0.00)->change();
            $table->integer('position')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->double('price', 8, 2)->change();
        });

        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->double('price', 8, 2)->change();
            $table->integer('position')->change();
        });
    }
};
