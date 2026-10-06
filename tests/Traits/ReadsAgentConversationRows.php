<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;

trait ReadsAgentConversationRows
{
    protected function conversationRow(string $id): object
    {
        return DB::connection('intelligence')->table('agent_conversations')->where('id', $id)->first();
    }

    protected function messageRow(string $id): object
    {
        return DB::connection('intelligence')->table('agent_conversation_messages')->where('id', $id)->first();
    }
}
