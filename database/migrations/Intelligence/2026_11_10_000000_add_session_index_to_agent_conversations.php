<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private const string INDEX = 'agent_conversations_session_idx';

    /**
     * A session's conversation is found by its `title`, oldest first. With no index on `title` the
     * `ORDER BY id LIMIT 1` makes MySQL walk the primary key until a row matches, and the first turn of
     * a session matches none, so it read the whole table: minutes per turn. Guarded because the index
     * is applied by hand on production ahead of the deploy.
     */
    public function up(): void
    {
        if (Schema::connection('intelligence')->hasIndex('agent_conversations', self::INDEX)) {
            return;
        }

        Schema::connection('intelligence')->table('agent_conversations', function (Blueprint $table): void {
            $table->index(['apps_id', 'companies_id', 'title'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::connection('intelligence')->hasIndex('agent_conversations', self::INDEX)) {
            return;
        }

        Schema::connection('intelligence')->table('agent_conversations', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
