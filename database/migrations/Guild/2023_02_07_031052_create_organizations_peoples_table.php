<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrganizationsPeoplesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('organizations_peoples', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('organizations_id')->nullable()->index('organizations_id');
            $table->bigInteger('peoples_id')->nullable()->index('people_id');
            $table->dateTime('created_at')->index('created_at');

            $table->unique(['organizations_id', 'peoples_id'], 'organizations_id_people_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('organizations_peoples');
    }
}
