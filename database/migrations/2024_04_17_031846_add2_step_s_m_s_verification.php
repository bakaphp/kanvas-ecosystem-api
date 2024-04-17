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
            $table->string('two_step_phone_number')->nullable()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('configuration');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->dropColumn('verification_phone_number');
            $table->dropColumn('email_verified_at');
            $table->dropColumn('phone_verified_at');
        });
    }
};
