<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToSystemModulesFormsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('system_modules_forms', function (Blueprint $table) {
            $table->foreign(['apps_id'], 'system_modules_forms_ibfk_1')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['companies_id'], 'system_modules_forms_ibfk_2')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['system_modules_id'], 'system_modules_forms_ibfk_3')->references(['id'])->on('system_modules')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('system_modules_forms', function (Blueprint $table) {
            $table->dropForeign('system_modules_forms_ibfk_1');
            $table->dropForeign('system_modules_forms_ibfk_2');
            $table->dropForeign('system_modules_forms_ibfk_3');
        });
    }
}
