<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompaniesSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('companies_settings', function (Blueprint $table) {
            $table->integer('companies_id');
            $table->string('name', 45)->index('index4');
            $table->longText('value');
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->nullable()->index('is_deleted');

            $table->index(['companies_id', 'name'], 'index5');
            $table->primary(['companies_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('companies_settings');
    }
}
