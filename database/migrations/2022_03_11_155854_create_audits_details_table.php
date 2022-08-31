<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuditsDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('audits_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('audits_id')->index('idx1');
            $table->string('field_name', 32)->index('field_name');
            $table->text('old_value')->nullable();
            $table->text('old_value_text')->nullable();
            $table->text('new_value')->nullable();
            $table->text('new_value_text')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('audits_details');
    }
}
