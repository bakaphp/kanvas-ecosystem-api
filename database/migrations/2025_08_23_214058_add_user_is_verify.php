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
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->boolean('is_verified')->after('status')->default(false);
            $table->string('user_verification_token', 100)->after('is_verified')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->dropColumn('is_verified');
            $table->dropColumn('user_verification_token');
        });
    }
};
