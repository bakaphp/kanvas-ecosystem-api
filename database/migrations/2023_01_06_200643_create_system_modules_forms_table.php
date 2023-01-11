<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSystemModulesFormsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('system_modules_forms', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('apps_id');
            $table->integer('companies_id');
            $table->integer('system_modules_id');
            $table->string('name', 64);
            $table->string('slug', 64);
            $table->text('form_schema');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->nullable()->default(0)->index('is_deleted');

            $table->unique(['apps_id', 'companies_id', 'name', 'slug'], 'system_modules_forms_UN');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('system_modules_forms');
    }
}
