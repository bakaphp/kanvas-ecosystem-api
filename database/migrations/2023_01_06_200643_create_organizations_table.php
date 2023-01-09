<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrganizationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('regions_id');
            $table->integer('countries_id');
            $table->string('name', 128);
            $table->string('slug', 128);
            $table->string('shortname', 16);
            $table->string('logo', 128)->nullable();
            $table->string('icon', 128)->nullable();
            $table->integer('is_active');
            $table->date('founded_date');
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->integer('is_deleted')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('organizations');
    }
}
