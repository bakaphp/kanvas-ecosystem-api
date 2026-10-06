<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Models\AgentConversation;

/**
 * A raw `agent_conversation_messages` row, written past every store so a test can shape it exactly:
 * a row stamped with a `kind`, or one from before the column that says what it is only in `meta`.
 */
trait WritesConversationRows
{
    /**
     * @param array<string, mixed> $columns overrides, e.g. ['kind' => 'summary'] or ['meta' => [...]]
     */
    protected function conversationRow(
        AgentConversation $conversation,
        string $role,
        string $content,
        array $columns = [],
    ): string {
        $id = (string) Str::uuid7();
        $writtenAt = now()->addSecond();
        $row = [
            'id' => $id,
            'conversation_id' => $conversation->getKey(),
            'user_id' => $conversation->user_id,
            'agent' => 'Test\\Stub\\Handler',
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'usage' => '[]',
            'meta' => [],
            'status' => 'completed',
            'created_at' => $writtenAt,
            'updated_at' => $writtenAt,
            ...$columns,
        ];
        $row['meta'] = json_encode($row['meta']);

        DB::connection('intelligence')->table('agent_conversation_messages')->insert($row);

        return $id;
    }
}
