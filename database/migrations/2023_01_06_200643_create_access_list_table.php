<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccessListTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('access_list', function (Blueprint $table) {
            $table->string('roles_name', 32)->index('roles_name');
            $table->string('resources_name', 32);
            $table->string('access_name', 32);
            $table->integer('roles_id');
            $table->integer('allowed')->index('allowed');
            $table->integer('apps_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');

            $table->primary(['roles_id', 'resources_name', 'access_name', 'apps_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('access_list');
    }
}
