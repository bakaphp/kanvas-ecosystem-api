<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomFieldsValuesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('custom_fields_values', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('custom_fields_id')->index('custom_fields_id_entity_id_custom_fields_modules_id');
            $table->char('label', 50)->default('');
            $table->longText('value');
            $table->integer('is_default')->default(0);
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');

            $table->index(['custom_fields_id', 'is_default'], 'custom_fields_id_entity_id_custom_fields_modules_id_is_default');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('custom_fields_values');
    }
}
