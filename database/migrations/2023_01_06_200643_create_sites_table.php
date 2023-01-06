<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSitesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('users_id')->default(0);
            $table->integer('companies_id')->default(0);
            $table->string('title', 200);
            $table->string('key', 128);
            $table->text('description');
            $table->string('domain', 200);
            $table->integer('status')->default(1);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->boolean('is_deleted')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sites');
    }
}
