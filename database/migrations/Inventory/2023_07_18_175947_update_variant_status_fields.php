<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products_variants', function (Blueprint $table) {
            $table->dropColumn('is_published');
            $table->bigInteger('status_id')->unsigned()->after('sku')->nullable();
            $table->index('status_id');
        });

        Schema::table('products_variants_warehouses', function (Blueprint $table) {
            $table->dropColumn('is_published');
            $table->bigInteger('status_id')->unsigned()->after('sku')->nullable();
            $table->index('status_id');
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
