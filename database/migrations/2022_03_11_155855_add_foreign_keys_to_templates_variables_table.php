<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToTemplatesVariablesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('templates_variables', function (Blueprint $table) {
            $table->foreign(['companies_id'], 'templates_variables_ibfk_1')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'templates_variables_ibfk_2')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['system_modules_id'], 'templates_variables_ibfk_3')->references(['id'])->on('system_modules')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('templates_variables', function (Blueprint $table) {
            $table->dropForeign('templates_variables_ibfk_1');
            $table->dropForeign('templates_variables_ibfk_2');
            $table->dropForeign('templates_variables_ibfk_3');
        });
    }
}
