<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    /**
     * session_uuid mirrors sessions.uuid, which is not a real UUID for channel-scoped chats —
     * SessionChannelService::buildChannelSessionUuid builds `{channel-slug}-{appId}-{companyId}`
     * (e.g. `slack-t0bc3htqyac-d0bkmc6tkat-57-11733`). sessions.uuid was widened to 225 for the
     * same reason; char(36) here truncated the insert (SQLSTATE 22001).
     */
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_scheduled_actions', function (Blueprint $table) {
            $table->string('session_uuid', 225)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_scheduled_actions', function (Blueprint $table) {
            $table->char('session_uuid', 36)->nullable()->change();
        });
    }
};
