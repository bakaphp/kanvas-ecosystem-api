<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMessageTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('message_types', function (Blueprint $table) {
            $table->integer('id', true);
            $table->char('uuid', 36)->nullable()->index('uuid');
            $table->integer('apps_id')->index('apps_id');
            $table->integer('languages_id')->index('languages_id');
            $table->string('name', 150);
            $table->char('verb', 50)->default('')->index('verb');
            $table->longText('template')->nullable();
            $table->longText('templates_plura')->nullable();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('message_types');
    }
}
