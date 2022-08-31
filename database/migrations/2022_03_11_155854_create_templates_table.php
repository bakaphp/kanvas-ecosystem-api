<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('users_id')->index('users_id');
            $table->integer('companies_id');
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->string('name', 64);
            $table->longText('template');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');

            $table->unique(['companies_id', 'apps_id', 'name'], 'company_id_app_id_name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('templates');
    }
}
