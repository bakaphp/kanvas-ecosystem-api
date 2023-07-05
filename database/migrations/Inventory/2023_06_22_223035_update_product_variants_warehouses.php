<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            Schema::disableForeignKeyConstraints();

            $table->dropForeign('products_variants_warehouses_warehouses_id_foreign');
            $table->dropForeign('products_variants_warehouses_products_variants_id_foreign');

            $table->dropPrimary();
            Schema::enableForeignKeyConstraints();
        });

        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            Schema::disableForeignKeyConstraints();
            $table->id()->first();
            $table->foreign('products_variants_id')->references('id')->on('products_variants');
            $table->foreign('warehouses_id')->references('id')->on('warehouses');
            $table->unique(['products_variants_id', 'warehouses_id'], 'product_variants_warehouses_unique');
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
