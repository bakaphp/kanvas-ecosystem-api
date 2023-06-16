<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersListsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('users_lists', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('apps_id')->index('apps_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->string('name', 255);
            $table->char('slug', 100)->nullable()->default('')->index('slug');
            $table->text('description')->nullable();
            $table->boolean('is_public')->nullable()->index('is_public');
            $table->boolean('is_default')->default(false)->index('is_default');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->index('is_deleted');

            $table->index(['apps_id', 'companies_id', 'users_id'], 'apps_id_companies_id_users_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('users_lists');
    }
}
