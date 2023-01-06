<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppsCustomFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('apps_custom_fields', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('companies_id')->index('companies_id');
            $table->integer('users_id')->index('users_id');
            $table->string('model_name')->index('model_name');
            $table->bigInteger('entity_id')->default(0)->index('entity_id');
            $table->string('name')->index('name');
            $table->string('label')->index('label');
            $table->longText('value')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');

            $table->index(['model_name', 'entity_id', 'name'], 'model_name_3');
            $table->index(['model_name', 'entity_id'], 'model_name_2');
            $table->index(['companies_id', 'model_name', 'entity_id', 'name'], 'companies_id_model_name_entity_id_name');
            $table->index(['companies_id', 'model_name', 'entity_id'], 'companies_id_model_name_entity_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('apps_custom_fields');
    }
}
