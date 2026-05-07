<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixUsersFollowsAppsIdType extends Migration
{
    public function up(): void
    {
        Schema::table('users_follows', function (Blueprint $table) {
            // Change to nullable integer
            $table->integer('apps_id')->nullable()->change();

            // Re-add index
            $table->index('apps_id');
        });
    }

    public function down(): void
    {
        Schema::table('users_follows', function (Blueprint $table) {
            $table->dropIndex('apps_id');

            // Revert to nullable string
            $table->string('apps_id', 255)->nullable()->change();

            $table->index('apps_id');
        });
    }
}
