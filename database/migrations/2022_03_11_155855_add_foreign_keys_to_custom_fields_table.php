<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToCustomFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->foreign(['users_id'], 'custom_fields_ibfk_1')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['companies_id'], 'custom_fields_ibfk_2')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['fields_type_id'], 'custom_fields_ibfk_3')->references(['id'])->on('custom_fields_types')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropForeign('custom_fields_ibfk_1');
            $table->dropForeign('custom_fields_ibfk_2');
            $table->dropForeign('custom_fields_ibfk_3');
        });
    }
}
