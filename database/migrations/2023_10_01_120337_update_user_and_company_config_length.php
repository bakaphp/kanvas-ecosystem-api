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
        Schema::table('user_config', function (Blueprint $table) {
            $table->string('name', 255)->change();
            $table->tinyInteger('is_public')->after('value')->default(1)->index('is_public');
        });

        Schema::table('companies_settings', function (Blueprint $table) {
            $table->string('name', 255)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_config', function (Blueprint $table) {
            $table->string('name', 45)->change();
            $table->dropColumn('is_public');
        });

        Schema::table('companies_settings', function (Blueprint $table) {
            $table->string('name', 45)->change();
        });
    }
};
