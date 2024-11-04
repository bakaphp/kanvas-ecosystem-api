<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::table('user_messages', function (Blueprint $table) {
            $table->dropPrimary(['messages_id', 'users_id']);

            // Add the new composite primary key
            $table->primary(['messages_id', 'users_id', 'apps_id']);
        });
    }

    public function down()
    {
        Schema::table('user_messages', function (Blueprint $table) {
            // Drop the composite primary key with apps_id
            $table->dropPrimary(['messages_id', 'users_id', 'apps_id']);

            // Revert back to the original primary key
            $table->primary(['messages_id', 'users_id']);
        });
    }
};
