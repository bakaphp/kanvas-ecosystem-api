<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersAssociatedAppsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users_associated_apps', function (Blueprint $table) {
            $table->integer('users_id');
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->integer('companies_id');
            $table->string('identify_id', 45)->nullable()->index('identify_id');
            $table->boolean('user_active')->default(true)->index('user_active');
            $table->string('user_role', 45)->nullable();
            $table->string('password')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');

            $table->primary(['users_id', 'apps_id', 'companies_id'], 'user_assoc_pri');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users_associated_apps');
    }
}
