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
        Schema::table('sessions', function (Blueprint $table) {
            $table->char('id', 45)->change();
            $table->char('ip', 39)->change();
            $table->char('page', 45)->change();

            $table->index('apps_id');
            $table->index(['id' , 'users_id', 'apps_id']);
            $table->index(['id' , 'users_id']);
        });

        Schema::table('session_keys', function (Blueprint $table) {
            $table->char('sessions_id', 45)->change();
            $table->char('last_ip', 39)->change();

            $table->index(['sessions_id' , 'users_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

    }
};
