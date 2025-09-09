<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->uuid('uuid')->index();
            $table->bigInteger('resources_id')->index();
            $table->string('resources_type', 255)->index();
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->string('rrule', 512);             // iCal RRULE
            $table->unsignedSmallInteger('slot_duration_min');  // 10, 60, 90...
            $table->integer('lead_time_min')->default(0);       // antelación mínima
            $table->integer('cutoff_time_min')->default(0);     // límite de reserva
            $table->unsignedSmallInteger('capacity_override')->nullable();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->unsignedBigInteger('resources_id')->index();
            $table->string('resources_type', 255)->index();
            $table->dateTime('window_start');
            $table->dateTime('window_end');
            $table->enum('kind', ['blackout','extra_open']);
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->index(['resources_id','window_start','window_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->dropColumn('schedule_rules');
        });
    }
};
