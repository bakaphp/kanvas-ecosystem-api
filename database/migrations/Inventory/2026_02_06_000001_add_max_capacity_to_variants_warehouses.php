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
            $table->integer('max_capacity')->nullable()->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->dropColumn('max_capacity');
        });
    }
};
