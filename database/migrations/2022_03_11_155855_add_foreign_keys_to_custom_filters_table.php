<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToCustomFiltersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('custom_filters', function (Blueprint $table) {
            $table->foreign(['system_modules_id'], 'custom_filters_ibfk_1')->references(['id'])->on('system_modules')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'custom_filters_ibfk_2')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['companies_id'], 'custom_filters_ibfk_3')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['companies_branch_id'], 'custom_filters_ibfk_4')->references(['id'])->on('companies_branches')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['users_id'], 'custom_filters_ibfk_5')->references(['id'])->on('users')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('custom_filters', function (Blueprint $table) {
            $table->dropForeign('custom_filters_ibfk_1');
            $table->dropForeign('custom_filters_ibfk_2');
            $table->dropForeign('custom_filters_ibfk_3');
            $table->dropForeign('custom_filters_ibfk_4');
            $table->dropForeign('custom_filters_ibfk_5');
        });
    }
}
