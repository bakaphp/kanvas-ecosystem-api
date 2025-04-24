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
        Schema::table('integration_companies', function (Blueprint $table) {
            $table->tinyInteger('is_active')->default(1)->after('config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integration_companies', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
