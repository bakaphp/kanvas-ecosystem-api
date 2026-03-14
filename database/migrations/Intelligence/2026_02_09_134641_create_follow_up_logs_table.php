<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFollowUpLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('follow_up_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('apps_id')->index();
            $table->integer('companies_id')->index();
            $table->integer('leads_id')->index();
            $table->integer('follow_ups_id')->nullable()->index();
            $table->integer('follow_up_days_id')->nullable()->index();
            $table->integer('pipeline_stages_id')->index();
            $table->integer('sessions_id')->nullable()->index();

            // Tracking flags
            $table->boolean('entered_follow_up_action')->default(false)->index();
            $table->boolean('found_follow_up')->default(false)->index();
            $table->boolean('found_follow_up_day')->default(false)->index();
            $table->boolean('entered_create_message_action')->default(false)->index();
            $table->boolean('should_respond')->nullable()->index();
            $table->boolean('message_created')->default(false)->index();
            $table->boolean('message_sent')->default(false)->index();

            // Additional data
            $table->integer('messages_id')->nullable()->index();
            $table->string('communication_channel')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();

            $table->dateTime('created_at')->useCurrent()->index();
            $table->dateTime('updated_at')->nullable()->index();
            $table->integer('is_deleted')->default(0)->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('follow_up_logs');
    }
}
