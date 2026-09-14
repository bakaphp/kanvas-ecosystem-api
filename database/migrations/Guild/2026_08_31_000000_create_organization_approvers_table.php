<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrganizationApproversTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('organization_approvers', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('organizations_id')->index('organizations_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->dateTime('created_at')->index('created_at');

            $table->unique(['organizations_id', 'users_id'], 'organizations_id_users_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('organization_approvers');
    }
}
