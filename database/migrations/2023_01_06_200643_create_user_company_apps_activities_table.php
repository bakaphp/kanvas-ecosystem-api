<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserCompanyAppsActivitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_company_apps_activities', function (Blueprint $table) {
            $table->integer('companies_id');
            $table->integer('company_branches_id');
            $table->integer('apps_id');
            $table->string('key', 50);
            $table->string('value');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->nullable()->default(false)->index('is_deleted');

            $table->primary(['companies_id', 'company_branches_id', 'apps_id', 'key'], 'user_company_apps_activities_primary');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_company_apps_activities');
    }
}
