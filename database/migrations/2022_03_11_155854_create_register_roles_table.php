<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRegisterRolesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('register_roles', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->default('')->index('uuid');
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->integer('companies_id')->index('companies_id');
            $table->integer('roles_id')->index('roles_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->nullable()->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('register_roles');
    }
}
