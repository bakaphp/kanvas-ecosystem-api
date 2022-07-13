<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToAppsPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('apps_plans', function (Blueprint $table) {
            $table->foreign(['apps_id'], 'apps_plans_ibfk_1')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['currency_id'], 'apps_plans_ibfk_2')->references(['id'])->on('currencies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('apps_plans', function (Blueprint $table) {
            $table->dropForeign('apps_plans_ibfk_1');
            $table->dropForeign('apps_plans_ibfk_2');
        });
    }
}
