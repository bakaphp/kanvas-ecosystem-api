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
        Schema::table('products_types_attributes', function (Blueprint $table) {
            $table->boolean('is_required')->after('to_variant')->default(false);
            $table->index('is_required');
        });

        Schema::table('attributes', function (Blueprint $table) {
            $table->dropColumn('is_required');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
