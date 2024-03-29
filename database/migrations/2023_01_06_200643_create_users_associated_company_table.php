<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersAssociatedCompanyTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users_associated_company', function (Blueprint $table) {
            $table->integer('users_id');
            $table->integer('companies_id');
            $table->integer('companies_branches_id')->default(0)->index('companies_branches_id');
            $table->string('identify_id', 45)->nullable()->index('identify_id');
            $table->boolean('user_active')->default(true)->index('user_active');
            $table->string('user_role', 45)->nullable()->index('user_role');
            $table->longText('configuration')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');

            $table->primary(['users_id', 'companies_id', 'companies_branches_id'], 'users_company_branches_id');
            $table->unique(['users_id', 'companies_branches_id', 'companies_id'], 'users_id');
            $table->index(['users_id', 'companies_id'], 'users_id_2');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users_associated_company');
    }
}
