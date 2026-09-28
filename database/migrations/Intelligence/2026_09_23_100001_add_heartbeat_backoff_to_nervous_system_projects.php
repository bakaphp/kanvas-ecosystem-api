<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    /**
     * A project stuck on the same blocked/stalled work re-woke its PM on every heartbeat — a full-context
     * LLM turn every 15 minutes, forever. These columns let the heartbeat back off (1h → 4h → 24h) while
     * the stuck state is unchanged, and snap back to the normal cadence as soon as it changes.
     */
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_projects', function (Blueprint $table) {
            $table->unsignedSmallInteger('heartbeat_max_backoff_minutes')->default(1440)->after('next_heartbeat_at');
            $table->unsignedTinyInteger('heartbeat_backoff_level')->default(0)->after('heartbeat_max_backoff_minutes');
            $table->char('heartbeat_attention_hash', 40)->nullable()->after('heartbeat_backoff_level');
            $table->timestamp('heartbeat_backoff_until')->nullable()->after('heartbeat_attention_hash');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_projects', function (Blueprint $table) {
            $table->dropColumn([
                'heartbeat_max_backoff_minutes',
                'heartbeat_backoff_level',
                'heartbeat_attention_hash',
                'heartbeat_backoff_until',
            ]);
        });
    }
};
