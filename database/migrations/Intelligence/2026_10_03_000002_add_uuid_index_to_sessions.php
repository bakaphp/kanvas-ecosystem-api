<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sessions.uuid` is how every chat turn finds its session (`Session::scopeFromAgent()`, the connector
 * responders, `conversationForSession()` keys conversations by it), and the table had no index on it — each
 * lookup scanned the table. Found when the participant backfill's per-uuid lookup ran 201k times in prod.
 * Guarded because prod got the index by hand during that incident.
 */
return new class () extends Migration {
    public function getConnection(): ?string
    {
        return 'intelligence';
    }

    public function up(): void
    {
        if (Schema::connection('intelligence')->hasIndex('sessions', 'sessions_uuid_index')) {
            return;
        }

        Schema::connection('intelligence')->table('sessions', function (Blueprint $table) {
            $table->index('uuid', 'sessions_uuid_index');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('sessions', function (Blueprint $table) {
            $table->dropIndex('sessions_uuid_index');
        });
    }
};
