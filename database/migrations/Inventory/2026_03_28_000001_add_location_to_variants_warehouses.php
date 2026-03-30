<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('inventory')->table('products_variants_warehouses', function (Blueprint $table) {
            $table->decimal('latitude', 10, 6)->nullable()->after('serial_number');
            $table->decimal('longitude', 10, 6)->nullable()->after('latitude');
            $table->index(['latitude', 'longitude'], 'idx_geo_location');
        });
    }

    public function down(): void
    {
        Schema::connection('inventory')->table('products_variants_warehouses', function (Blueprint $table) {
            $table->dropIndex('idx_geo_location');
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
