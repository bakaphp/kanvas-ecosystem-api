<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRolesInheritsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('roles_inherits', function (Blueprint $table) {
            $table->string('roles_name', 32);
            $table->integer('roles_id')->index('roles_id');
            $table->integer('roles_inherit');

            $table->primary(['roles_name', 'roles_inherit']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('roles_inherits');
    }
}
