<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersInviteTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users_invite', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('invite_hash', 200)->index('invite_hash');
            $table->integer('users_id')->index('users_id');
            $table->integer('companies_id')->index('companies_id');
            $table->integer('role_id')->index('role_id');
            $table->unsignedInteger('apps_id')->index('app_id');
            $table->string('email', 64);
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users_invite');
    }
}
