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
        Schema::table('companies_address', function (Blueprint $table) {
            $table->string('fullname')->nullable();
            $table->string('phone')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies_address', function (Blueprint $table) {
            $table->dropColumn('fullname');
            $table->dropColumn('phone');
        });
    }
};
