<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuditsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('entity_id')->index('idx1');
            $table->string('model_name', 64)->index('idx2');
            $table->unsignedInteger('users_id')->index('idx3');
            $table->string('ip', 15);
            $table->char('type', 1)->index('idx4');
            $table->dateTime('created_at')->index('created_at');

            $table->index(['model_name', 'type'], 'idx5');
            $table->index(['entity_id', 'model_name', 'type'], 'idx6');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('audits');
    }
}
