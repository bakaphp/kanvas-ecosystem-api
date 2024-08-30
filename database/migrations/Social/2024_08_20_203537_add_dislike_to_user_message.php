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
        Schema::table('user_messages', function (Blueprint $table) {
            $table->boolean('is_disliked')->after('is_liked')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_messages', function (Blueprint $table) {
            $table->dropColumn('is_disliked');
        });
    }
};
