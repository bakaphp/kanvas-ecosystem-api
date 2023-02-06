<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersReactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('users_reactions', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('users_id')->index('users_id');
            $table->bigInteger('reactions_id')->index('reactions_id');
            $table->char('entity_id', 36)->index('entity_id');
            $table->char('entity_namespace')->index('entity_namespace');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['users_id', 'reactions_id', 'entity_namespace', 'is_deleted'], 'users_id_reactions_id_entity_namespace_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('users_reactions');
    }
}
