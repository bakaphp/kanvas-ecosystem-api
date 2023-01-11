<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppsPlansSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('apps_plans_settings', function (Blueprint $table) {
            $table->integer('apps_plans_id')->index('plansapps');
            $table->integer('apps_id');
            $table->string('key', 100)->index('appkey');
            $table->string('value');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->nullable()->default(false)->index('is_deleted');

            $table->unique(['apps_plans_id', 'key'], 'appskeys');
            $table->primary(['apps_plans_id', 'apps_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('apps_plans_settings');
    }
}
