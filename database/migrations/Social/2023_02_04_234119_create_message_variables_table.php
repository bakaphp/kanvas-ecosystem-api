<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMessageVariablesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('message_variables', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('message_id')->index('message_id');
            $table->string('key', 100)->index('key');
            $table->string('value', 45);
            $table->dateTime('created_at')->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('message_variables');
    }
}
