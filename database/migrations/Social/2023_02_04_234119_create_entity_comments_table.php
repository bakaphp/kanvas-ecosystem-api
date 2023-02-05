<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEntityCommentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('entity_comments', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->char('entity_id', 50)->index('message_id');
            $table->char('entity_namespace')->index('entity_namespace');
            $table->integer('apps_id')->index('apps_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->longText('message');
            $table->integer('reactions_count')->default(0);
            $table->bigInteger('parent_id')->default(0)->index('parent_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');

            $table->index(['id', 'apps_id', 'is_deleted'], 'id_apps_id_is_deleted');
            $table->index(['entity_id', 'entity_namespace', 'apps_id', 'companies_id', 'parent_id', 'is_deleted'], 'entity_id_entity_namespace_apps_id_csear');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('entity_comments');
    }
}
