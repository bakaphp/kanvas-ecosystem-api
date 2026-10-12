<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A task parked on a person or a date (status `waiting`) records when its wake is due, so the
// heartbeat can tell "waiting as planned" from "the wake never fired" without reading the schedule.
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tasks', function (Blueprint $table) {
            $table->dateTime('wake_at')->nullable()->after('blocked_reason');
            $table->index(['status', 'wake_at'], 'status_wake_at_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tasks', function (Blueprint $table) {
            $table->dropIndex('status_wake_at_idx');
            $table->dropColumn('wake_at');
        });
    }
};
