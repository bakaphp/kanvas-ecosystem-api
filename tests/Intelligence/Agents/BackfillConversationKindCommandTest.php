<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Tests\TestCase;
use Tests\Traits\MakesAgents;
use Tests\Traits\WritesConversationRows;

/**
 * Rows written before `kind` existed say what they are only inside `meta`; the backfill reads that
 * once and stamps the column, and leaves a row alone once it is stamped.
 */
class BackfillConversationKindCommandTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;
    use WritesConversationRows;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testRowsAreStampedFromMetaOnceAndOnlyOnce(): void
    {
        $user = auth()->user();
        $agent = $this->makeAgentFor($user);
        $sessionId = (string) Str::uuid();
        new KanvasConversationStore()->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: SystemUserAgent::class,
            userMessage: 'hello',
            assistantResponse: 'hi',
            agentId: $agent->getId(),
        );
        $conversation = AgentConversation::query()->where('agent_id', $agent->getId())->firstOrFail();
        $summary = $this->conversationRow(
            $conversation,
            'user',
            '## Previous conversation summary',
            ['meta' => ['__meta' => ['summary' => true]]],
        );
        $call = $this->conversationRow(
            $conversation,
            'assistant',
            '',
            ['meta' => ['__meta' => [], 'type' => 'tool_call']],
        );
        $result = $this->conversationRow(
            $conversation,
            'user',
            '',
            ['meta' => ['__meta' => [], 'type' => 'tool_call_result']],
        );
        $turn = $this->conversationRow(
            $conversation,
            'assistant',
            'a plain reply',
            ['meta' => ['__meta' => ['stop_reason' => 'STOP']]],
        );

        $this->artisan('agents:backfill-conversation-kind')->assertSuccessful();

        $this->assertSame('summary', $this->kindOf($summary));
        $this->assertSame('tool_call', $this->kindOf($call));
        $this->assertSame('tool_call_result', $this->kindOf($result));
        $this->assertNull($this->kindOf($turn), 'A conversational turn has no kind');

        DB::connection('intelligence')->table('agent_conversation_messages')->where('id', $summary)->update(['kind' => 'tool_call']);
        $this->artisan('agents:backfill-conversation-kind')->assertSuccessful();
        $this->assertSame('tool_call', $this->kindOf($summary), 'A stamped row is never rewritten');
    }

    private function kindOf(string $id): ?string
    {
        return DB::connection('intelligence')->table('agent_conversation_messages')->where('id', $id)->value('kind');
    }
}
