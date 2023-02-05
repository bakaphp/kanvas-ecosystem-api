<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersListsEntitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('users_lists_entities', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('users_lists_id')->index('users_lists_id');
            $table->char('entity_id', 50)->index('entity_id');
            $table->char('entity_namespace')->index('entity_namespace');
            $table->boolean('is_pin')->default(false)->index('is_pin');
            $table->text('description')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['entity_id', 'entity_namespace'], 'entity_id_entity_namespace');
            $table->index(['users_lists_id', 'entity_namespace'], 'users_lists_id_entity_namespace');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('users_lists_entities');
    }
}
