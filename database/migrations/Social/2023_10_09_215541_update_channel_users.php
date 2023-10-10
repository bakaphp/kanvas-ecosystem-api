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
        Schema::connection('social')->table('channel_users', function (Blueprint $table) {

            $table->timestamp('created_at')->useCurrent()->change();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_users', function (Blueprint $table) {

        });
    }
};
