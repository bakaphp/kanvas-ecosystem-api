<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRotationUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('rotation_users', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('rotations_id')->index('rotations_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->string('name', 45)->nullable();
            $table->string('email', 45)->nullable()->index('email');
            $table->string('phone', 45)->nullable();
            $table->integer('hits')->nullable()->default(0)->index('hits');
            $table->double('percentage')->nullable()->index('percentage');
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('rotation_users');
    }
}
