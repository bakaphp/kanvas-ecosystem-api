<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('inventory')->table('products_variants_warehouses_price_history', function (Blueprint $table) {
            $table->bigInteger('users_id')->unsigned()->default(0)->after('price');
            $table->index('users_id');
        });

        Schema::connection('inventory')->table('products_variants_warehouse_channel_price_history', function (Blueprint $table) {
            $table->bigInteger('users_id')->unsigned()->default(0)->after('price');
            $table->index('users_id');
        });
    }

    public function down(): void
    {
        Schema::connection('inventory')->table('products_variants_warehouses_price_history', function (Blueprint $table) {
            $table->dropIndex(['users_id']);
            $table->dropColumn('users_id');
        });

        Schema::connection('inventory')->table('products_variants_warehouse_channel_price_history', function (Blueprint $table) {
            $table->dropIndex(['users_id']);
            $table->dropColumn('users_id');
        });
    }
};
