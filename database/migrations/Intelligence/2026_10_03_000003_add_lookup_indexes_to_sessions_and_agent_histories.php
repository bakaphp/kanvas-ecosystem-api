<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After `sessions_uuid_index`, `sessions` still resolved every other lookup through `is_deleted`, which
 * scans half the table: by entity + agent (`CreateSessionAction`, the `firstOrCreate` keys,
 * `PlanBudgetService`, follow-up), by agent alone (`Session::scopeFromAgent()`) and by channel (the ADK
 * and SalesAssist responders). `agent_histories` is read per Laravel-agent turn by agent + entity and
 * resolved as an index merge with a filesort. Guarded so an index added by hand on prod is skipped.
 */
return new class () extends Migration {
    private const INDEXES = [
        'sessions' => [
            'sessions_entity_agent_index' => ['entity_namespace', 'entity_id', 'agents_id'],
            'sessions_agents_id_index' => ['agents_id'],
            'sessions_channel_id_index' => ['channel_id'],
        ],
        'agent_histories' => [
            'agent_histories_agent_entity_created_index' => ['agent_id', 'entity_namespace', 'entity_id', 'created_at'],
        ],
    ];

    public function getConnection(): ?string
    {
        return 'intelligence';
    }

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::connection('intelligence')->hasIndex($table, $name)) {
                    continue;
                }

                Schema::connection('intelligence')->table($table, function (Blueprint $blueprint) use ($columns, $name) {
                    $blueprint->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (! Schema::connection('intelligence')->hasIndex($table, $name)) {
                    continue;
                }

                Schema::connection('intelligence')->table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }
};
