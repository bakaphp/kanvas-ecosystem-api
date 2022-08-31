<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToUserCompanyAppsActivitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_company_apps_activities', function (Blueprint $table) {
            $table->foreign(['companies_id'], 'user_company_apps_activities_ibfk_1')->references(['id'])->on('companies')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['company_branches_id'], 'user_company_apps_activities_ibfk_2')->references(['id'])->on('companies_branches')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['apps_id'], 'user_company_apps_activities_ibfk_3')->references(['id'])->on('apps')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_company_apps_activities', function (Blueprint $table) {
            $table->dropForeign('user_company_apps_activities_ibfk_1');
            $table->dropForeign('user_company_apps_activities_ibfk_2');
            $table->dropForeign('user_company_apps_activities_ibfk_3');
        });
    }
}
