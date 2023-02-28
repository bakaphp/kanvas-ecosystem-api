<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLeadsAccessTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('leads_access', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('companies_id')->index('companies_id');
            $table->unsignedInteger('companies_branches_id')->index('companies_branches_id');
            $table->unsignedInteger('leads_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->unsignedInteger('is_deleted')->default('0')->index('is_deleted');

            $table->unique(['users_id', 'leads_id'], 'users_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('leads_access');
    }
}
