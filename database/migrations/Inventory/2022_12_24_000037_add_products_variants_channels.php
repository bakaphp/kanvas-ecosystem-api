<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products_variants_channels', function (Blueprint $table) {
            $table->primary(['products_variants_id', 'channels_id', 'warehouses_id'], 'products_variants_channels_primary');
            $table->bigInteger('products_variants_id')->unsigned();
            $table->bigInteger('channels_id')->unsigned();
            $table->bigInteger('warehouses_id')->unsigned();
            $table->float('price', 10, 2);
            $table->float('discounted_price', 10, 2);
            $table->boolean('is_published')->default(0);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index('price');
            $table->index('discounted_price');
            $table->foreign('products_variants_id')->references('id')->on('products_variants');
            $table->foreign('channels_id')->references('id')->on('channels');
            $table->foreign('warehouses_id')->references('id')->on('warehouses');
            $table->index('is_published');
            $table->index('is_deleted');
            $table->index('created_at');
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products_variants_channels');
    }
};
