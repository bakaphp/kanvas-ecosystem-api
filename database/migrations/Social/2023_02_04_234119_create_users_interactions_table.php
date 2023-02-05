<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersInteractionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('users_interactions', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('users_id')->index('users_id');
            $table->char('entity_id', 36)->index('entity_id');
            $table->char('entity_namespace')->index('entity_namespace');
            $table->integer('interactions_id')->index('interactions_id');
            $table->longText('notes')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['users_id', 'entity_id', 'entity_namespace', 'interactions_id', 'is_deleted'], 'users_id_entity_id_entity_namespace_interactions_id_is_deleted');
            $table->index(['entity_id', 'entity_namespace', 'interactions_id', 'is_deleted'], 'entity_id_2');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('users_interactions');
    }
}
