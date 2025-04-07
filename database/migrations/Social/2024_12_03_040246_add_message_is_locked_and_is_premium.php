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
        Schema::table('messages', function (Blueprint $table) {
            $table->tinyInteger('is_locked')->after('is_public')->default(0)->index('is_locked');
            $table->tinyInteger('is_premium')->after('is_locked')->default(0)->index('is_premium');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('is_locked');
            $table->dropColumn('is_premium');
        });
    }
};
