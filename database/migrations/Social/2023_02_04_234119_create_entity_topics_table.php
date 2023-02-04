<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEntityTopicsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('entity_topics', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->char('entity_id', 50)->index('message_id');
            $table->char('entity_namespace')->index('entity_namespace');
            $table->integer('apps_id')->index('apps_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->bigInteger('topics_id')->index('topics_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');

            $table->index(['id', 'apps_id', 'is_deleted'], 'id_apps_id_is_deleted');
            $table->index(['entity_id', 'entity_namespace', 'apps_id', 'companies_id', 'topics_id', 'is_deleted'], 'entity_id_entity_namespace_asearch');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('entity_topics');
    }
}
