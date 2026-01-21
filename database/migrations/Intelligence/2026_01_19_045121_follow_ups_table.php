<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->integer('follow_up_type')->default(0);
            $table->bigInteger('pipelines_id');
            $table->string('name');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();
        });
        Schema::create('follow_up_days', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('follow_ups_id');
            $table->bigInteger('pipeline_stages_id');
            $table->string('name');
            $table->integer('time_value');
            $table->string('time_unit')->nullable();
            $table->integer('weight')->default(0);
            $table->boolean('calendar_day')->default(1);
            $table->integer('move_to_stage_id')->nullable();
            $table->boolean('send_message')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();
        });
        Schema::create('follow_up_templates', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('follow_up_days_id');
            $table->string('communication_channel');
            $table->string('name');
            $table->text('template');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(0)->index();
            $table->foreign('follow_up_days_id')->references('id')->on('follow_up_days')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('follow_up_days');
        Schema::dropIfExists('follow_up_templates');
    }
};
