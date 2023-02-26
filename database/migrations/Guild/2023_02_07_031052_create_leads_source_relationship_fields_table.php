<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsSourceRelationshipFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads_source_relationship_fields', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('companies_id')->nullable()->index('companies_id');
            $table->integer('custom_fields_id')->nullable()->index('custom_fields_id');
            $table->integer('source_id')->nullable()->index('source_id');
            $table->string('source_field_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('leads_source_relationship_fields');
    }
}
