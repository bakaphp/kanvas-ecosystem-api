<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersDeletionRequestsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users_deletion_requests', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('users_id')->index('users_id');
            $table->integer('apps_id')->index('apps_id');
            $table->string('email')->index('email');
            $table->text('data')->nullable();
            $table->dateTime('request_date')->index('request_date');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['users_id', 'apps_id', 'is_deleted'], 'users_id_apps_id_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users_deletion_requests');
    }
}
