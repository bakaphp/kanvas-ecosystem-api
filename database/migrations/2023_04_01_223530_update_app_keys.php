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
        Schema::table('apps_keys', function (Blueprint $table) {
            $table->string('scope', 250)->nullable()->after('users_id');
            $table->dateTime('expires_at')->nullable()->after('last_used_date')->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
