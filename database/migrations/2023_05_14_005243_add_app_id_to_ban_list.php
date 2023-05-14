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
        Schema::table('banlist', function (Blueprint $table) {
            $table->bigInteger('apps_id')->default(0)->after('users_id');
            $table->index('apps_id');
            $table->index('users_id');
            $table->index('ip');
            $table->index('email');
            $table->index(['apps_id', 'users_id', 'ip', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
