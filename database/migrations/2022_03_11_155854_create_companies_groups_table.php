<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompaniesGroupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('companies_groups', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->char('uuid', 36)->nullable()->index('uuid');
            $table->string('name', 100);
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->integer('users_id')->index('users_id');
            $table->string('stripe_id')->nullable()->index('stripe_id');
            $table->tinyInteger('is_default')->default(0)->index('is_default');
            $table->string('country_code', 64)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->integer('is_deleted')->default(0)->index('is_deleted');

            $table->index(['country_code', 'created_at', 'updated_at'], 'country_code_created_at_updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('companies_groups');
    }
}
