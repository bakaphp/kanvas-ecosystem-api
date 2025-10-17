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
        Schema::table('products_variants', function (Blueprint $table) {
            $table->decimal('base_cost', 12, 4)->default(0)->after('sku');
        });
        
        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->decimal('cost', 12, 4)->default(0)->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products_variants', function (Blueprint $table) {
            $table->dropColumn('base_cost');
        });

        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
