<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToUsersInviteTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users_invite', function (Blueprint $table) {
            $table->foreign(['users_id'], 'users_invite_ibfk_1')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['companies_id'], 'users_invite_ibfk_2')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'users_invite_ibfk_3')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['role_id'], 'users_invite_ibfk_4')->references(['id'])->on('roles')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users_invite', function (Blueprint $table) {
            $table->dropForeign('users_invite_ibfk_1');
            $table->dropForeign('users_invite_ibfk_2');
            $table->dropForeign('users_invite_ibfk_3');
            $table->dropForeign('users_invite_ibfk_4');
        });
    }
}
