<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
       * Run the migrations.
       *
       * @return void
       */
    public function up()
    {
        Schema::table('user_linked_sources', function (Blueprint $table) {
            $table->char('source_users_id', 128)->change();
            $table->char('source_users_id_text', 255)->nullable()->change();
            $table->char('source_username', 128)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_linked_sources', function (Blueprint $table) {
            $table->string('source_users_id', 64)->change();
            $table->string('source_users_id_text', 255)->nullable()->change();
            $table->string('source_username', 45)->change();
        });
    }
};
