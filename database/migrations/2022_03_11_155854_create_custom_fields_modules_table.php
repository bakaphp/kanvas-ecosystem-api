<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomFieldsModulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('custom_fields_modules', function (Blueprint $table) {
            $table->integer('id', true);
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->string('name', 64)->index('name');
            $table->string('model_name', 64)->index('model_name');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0);

            $table->index(['apps_id', 'model_name', 'is_deleted'], 'apps_id_model_name_is_deleted');
            $table->index(['apps_id', 'name', 'model_name'], 'apps_id_name_model_name');
            $table->index(['apps_id', 'name', 'model_name', 'is_deleted'], 'apps_id_name_model_name_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('custom_fields_modules');
    }
}
