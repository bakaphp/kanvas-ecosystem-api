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
        Schema::table('countries_cities', function (Blueprint $table) {
            $table->decimal('latitude', 10, 10)->nullable()->index('latitude');
            $table->decimal('longitude', 10, 10)->nullable()->index('longitude');
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
