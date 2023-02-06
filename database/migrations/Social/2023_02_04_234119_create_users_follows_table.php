<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersFollowsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('users_follows', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('users_id')->index('users_id');
            $table->char('entity_id', 50)->index('entity_id');
            $table->bigInteger('companies_id')->nullable()->index('companies_id');
            $table->bigInteger('companies_branches_id')->nullable()->index('companies_branches_id');
            $table->char('entity_namespace')->index('entity_namespace');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['users_id', 'entity_id', 'entity_namespace'], 'users_id_entity_id_entity_namespace');
            $table->index(['users_id', 'entity_namespace', 'is_deleted'], 'users_id_entity_namespace_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('users_follows');
    }
}
