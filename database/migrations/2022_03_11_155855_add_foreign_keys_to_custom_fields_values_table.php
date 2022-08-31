<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToCustomFieldsValuesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('custom_fields_values', function (Blueprint $table) {
            $table->foreign(['custom_fields_id'], 'custom_fields_values_ibfk_1')->references(['id'])->on('custom_fields')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('custom_fields_values', function (Blueprint $table) {
            $table->dropForeign('custom_fields_values_ibfk_1');
        });
    }
}
