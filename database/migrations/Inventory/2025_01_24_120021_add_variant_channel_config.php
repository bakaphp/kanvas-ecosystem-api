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
        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->json('config')->nullable()->after('is_new');
        });

        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->json('config')->nullable()->after('discounted_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->dropColumn('config');
        });

        Schema::table('products_variants_channels', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
