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
            $table->unsignedBigInteger('people_id')->nullable()->index()->after('phone_verified_at');
            $table->string('stripe_id', 255)->nullable()->index()->after('people_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_associated_apps', function (Blueprint $table) {
            $table->dropColumn('people_id');
        });
    }
};
