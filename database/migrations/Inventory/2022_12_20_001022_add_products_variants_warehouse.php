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
        Schema::create('products_variants_warehouses', function (Blueprint $table) {
            $table->primary(['products_variants_id', 'warehouses_id'], 'products_variants_warehouses_primary');
            $table->bigInteger('products_variants_id')->unsigned();
            $table->bigInteger('warehouses_id')->unsigned();
            $table->float('quantity')->default(0);
            $table->float('price');
            $table->char('sku', 190)->nullable();
            $table->integer('position');
            $table->char('serial_number', 190)->nullable();
            $table->boolean('is_oversellable')->default(0);
            $table->boolean('is_default')->default(0);
            $table->boolean('is_best_seller')->default(0);
            $table->boolean('is_on_sale')->default(0);
            $table->boolean('is_on_promo')->default(0);
            $table->boolean('can_pre_order')->default(0);
            $table->boolean('is_coming_son')->default(0);
            $table->boolean('is_new')->default(0);
            $table->boolean('is_published')->default(0);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('products_variants_id')->references('id')->on('products_variants');
            $table->foreign('warehouses_id')->references('id')->on('warehouses');
            $table->index('price');
            $table->index('serial_number');
            $table->index('is_deleted');
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('is_oversellable');
            $table->index('is_default');
            $table->index('is_best_seller');
            $table->index('is_on_sale');
            $table->index('is_on_promo');
            $table->index('can_pre_order');
            $table->index('is_coming_son');
            $table->index('is_new');
            $table->index('is_published');
            $table->index('position');
            $table->index('sku');
            $table->index('quantity');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products_variants_warehouses');
    }
};
