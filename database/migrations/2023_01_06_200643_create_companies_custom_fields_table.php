<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompaniesCustomFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('companies_custom_fields', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('companies_id');
            $table->integer('custom_fields_id');
            $table->text('value');
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->integer('is_deleted')->default(0);

            $table->index(['companies_id', 'custom_fields_id'], 'companies_id_custom_fields_id');
            $table->index(['created_at', 'updated_at', 'is_deleted'], 'created_at_updated_at_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('companies_custom_fields');
    }
}
