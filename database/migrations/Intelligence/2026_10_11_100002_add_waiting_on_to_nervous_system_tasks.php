<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Who owes the answer a waiting task is parked on, and the conversation the ask went out in. The
// person's next message to the agent carries the task without a search, and the wake can say which
// thread to re-read before asking again.
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('waiting_on_users_id')->nullable()->after('wake_at');
            $table->unsignedBigInteger('waiting_on_channel_id')->nullable()->after('waiting_on_users_id');
            $table->index(['status', 'waiting_on_users_id'], 'status_waiting_on_user_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_tasks', function (Blueprint $table) {
            $table->dropIndex('status_waiting_on_user_idx');
            $table->dropColumn(['waiting_on_users_id', 'waiting_on_channel_id']);
        });
    }
};
