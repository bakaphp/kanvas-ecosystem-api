<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChannelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('channels', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->string('name');
            $table->char('slug', 50)->nullable()->index('slugss');
            $table->string('description');
            $table->bigInteger('last_message_id')->nullable()->index('last_message_id');
            $table->char('entity_namespace', 50)->default('')->index('entity_namespace');
            $table->char('entity_id', 50)->default('')->index('entity_id');
            $table->tinyInteger('is_deleted')->default(0)->index('is_deleted');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('social')->dropIfExists('channels');
    }
}
