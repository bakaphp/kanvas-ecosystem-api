<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('users_id')->index('users_id');
            $table->integer('companies_id');
            $table->integer('apps_id');
            $table->string('name', 64)->index('name');
            $table->string('label', 64)->nullable()->index('label');
            $table->integer('custom_fields_modules_id')->index('custom_fields_modules_id');
            $table->integer('fields_type_id')->index('fields_type_id');
            $table->longText('attributes')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0);

            $table->index(['companies_id', 'apps_id'], 'companies_id_apps_id');
            $table->index(['companies_id', 'apps_id', 'label', 'custom_fields_modules_id', 'is_deleted'], 'companies_id_apps_id_label_custom_fields_modules_id_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('custom_fields');
    }
}
