<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserMessagesActivitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('social')->create('user_messages_activities', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('user_messages_id')->index('user_messages_id');
            $table->bigInteger('from_entity_id')->index('from_entity_id');
            $table->char('entity_namespace')->nullable()->index('entity_namespace');
            $table->string('username')->nullable();
            $table->string('type');
            $table->text('text');
            $table->dateTime('created_at')->useCurrent()->index('created_at');
            $table->dateTime('updated_at')->nullable()->useCurrent()->index('updated_at');
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
        Schema::connection('social')->dropIfExists('user_messages_activities');
    }
}
