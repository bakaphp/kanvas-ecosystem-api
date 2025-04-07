<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weight', 10, 2)->default(0)->after('status_id');
        });

        Schema::table('products_variants', function (Blueprint $table) {
            $table->decimal('weight', 10, 2)->default(0)->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('weight');
        });

        Schema::table('products_variants', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
