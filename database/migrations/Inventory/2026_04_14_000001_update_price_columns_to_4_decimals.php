<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->decimal('price', 15, 4)->default(0.0000)->change();
            $table->decimal('discounted_price', 15, 4)->default(0.0000)->change();
        });

        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->decimal('price', 15, 4)->default(0.0000)->change();
        });

        Schema::table('products_variants_warehouses_price_history', function (Blueprint $table) {
            $table->decimal('price', 15, 4)->change();
        });

        Schema::table('products_variants_warehouse_channel_price_history', function (Blueprint $table) {
            $table->decimal('price', 15, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->default(0.00)->change();
            $table->decimal('discounted_price', 15, 2)->default(0.00)->change();
        });

        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->default(0.00)->change();
        });

        Schema::table('products_variants_warehouses_price_history', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
        });

        Schema::table('products_variants_warehouse_channel_price_history', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
        });
    }
};
