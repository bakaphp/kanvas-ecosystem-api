<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products_variants', function (Blueprint $table) {
            $table->float('rating')->default(value: 0)->after('serial_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products_variants', function (Blueprint $table) {
            $table->dropColumn('rating');
        });
    }
};
