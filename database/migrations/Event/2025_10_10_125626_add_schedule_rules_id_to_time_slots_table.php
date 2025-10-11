<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {

    public function up(): void
    {
        Schema::table('time_slots', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_rules_id')->nullable()->after('resources_type');
            $table->index('schedule_rules_id');
            $table->foreign('schedule_rules_id')
                  ->references('id')
                  ->on('schedule_rules')
                  ->onDelete('set null');
        });

        Schema::table('event_versions', function (Blueprint $table) {
            $table->bigInteger('time_slot_id')->unsigned()->nullable()->after('event_id');
            $table->index('time_slot_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('time_slots', function (Blueprint $table) {
            $table->dropForeign(['schedule_rules_id']);
            $table->dropIndex(['schedule_rules_id']);
            $table->dropColumn('schedule_rules_id');
        });

        Schema::table('event_versions', function (Blueprint $table) {
            $table->dropIndex(['time_slot_id']);
            $table->dropColumn('time_slot_id');
        });
    }
};
