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
            $table->unsignedInteger('apps_id')->index('apps_id');
            $table->unsignedBigInteger('companies_id')->index('companies_id');
            $table->integer('follow_up_type')->default(0)->index('follow_up_type');
            $table->unsignedBigInteger('pipelines_id')->index('pipelines_id');
            $table->string('name');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->index('created_at');
            $table->timestamp('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(0)->index('is_deleted');

            $table->index(['apps_id', 'companies_id'], 'apps_id_companies_id');
            $table->index(['apps_id', 'pipelines_id', 'is_deleted'], 'apps_id_pipelines_id_is_deleted');
        });
        Schema::create('follow_up_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('follow_ups_id')->index('follow_ups_id');
            $table->unsignedBigInteger('pipeline_stages_id')->index('pipeline_stages_id');
            $table->string('name');
            $table->integer('time_value');
            $table->string('time_unit')->nullable();
            $table->integer('weight')->default(0)->index('weight');
            $table->boolean('calendar_day')->default(1);
            $table->unsignedInteger('move_to_stage_id')->nullable()->index('move_to_stage_id');
            $table->boolean('send_message')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->index('created_at');
            $table->timestamp('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(0)->index('is_deleted');

            $table->foreign('follow_ups_id')->references('id')->on('follow_ups');
            $table->index(['follow_ups_id', 'is_deleted'], 'follow_ups_id_is_deleted');
        });
        Schema::create('follow_up_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('follow_up_days_id')->index('follow_up_days_id');
            $table->string('communication_channel')->index('communication_channel');
            $table->string('name');
            $table->text('template');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->index('created_at');
            $table->timestamp('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(0)->index('is_deleted');

            $table->foreign('follow_up_days_id')->references('id')->on('follow_up_days');
            $table->index(['follow_up_days_id', 'is_deleted'], 'follow_up_days_id_is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_up_templates');
        Schema::dropIfExists('follow_up_days');
        Schema::dropIfExists('follow_ups');
    }
};
