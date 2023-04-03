<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppsKeysTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('apps_keys', function (Blueprint $table) {
            $table->char('client_id', 36)->index('client_id');
            $table->char('client_secret_id', 128)->index('client_secret_id');
            $table->integer('apps_id');
            $table->integer('users_id');
            $table->dateTime('last_used_date')->nullable()->index('last_used_date');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');

            //$table->unique(['apps_id', 'users_id']);
            $table->index(['client_id', 'apps_id'], 'client_id_apps_id');
            $table->index(['client_secret_id', 'apps_id'], 'client_secret_id_apps_id');
            $table->primary(['apps_id', 'users_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('apps_keys');
    }
}
