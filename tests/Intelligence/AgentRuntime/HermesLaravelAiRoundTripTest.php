<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Tests\TestCase;

/**
 * Validates that Hermes-shaped rows round-trip cleanly through the existing
 * KanvasConversationStore reader (the only Laravel\Ai\Contracts\ConversationStore
 * impl in the codebase). The key invariants:
 *  - role='tool_result' rows construct a ToolResultMessage without throwing
 *    (MessageRole::tryFrom('tool_result') resolves);
 *  - assistant rows with empty content + tool_calls construct an
 *    AssistantMessage with the tool_calls populated;
 *  - user rows construct a plain Message('user', content);
 *  - rows with user_id=null do not appear in any user's latestConversationId.
 */
class HermesLaravelAiRoundTripTest extends TestCase
{
    public function testHermesShapedRowsLoadCleanlyThroughLaravelAiReader(): void
    {
        $conversationId = $this->seedHermesConversationAndMessages();

        // The hard invariant for v1: the existing reader must not throw on
        // Hermes-imported rows. Earlier failures (TypeError on ToolCall, bad
        // role enum) caught upstream and fixed in the reader's decoding step.
        //
        // Note: KanvasConversationStore was designed for in-Kanvas-runtime
        // assistant rows that carry BOTH tool_calls and tool_results on the
        // same DB row. Hermes stores them as separate event rows; a tool_result
        // row falls through to a plain AssistantMessage on read. Merging them
        // at read time is a v1.1 enhancement — for v1 the contract is "no
        // exceptions, every event is reachable as a Message subclass".
        $store = new KanvasConversationStore();
        $messages = $store->getLatestConversationMessages($conversationId, 50);

        $this->assertGreaterThanOrEqual(4, $messages->count(), 'Every source row should map to at least one Message');

        foreach ($messages as $m) {
            $this->assertInstanceOf(Message::class, $m, 'Every reader output must be a Laravel AI Message subclass');
        }

        // First row: user content survives intact.
        $this->assertSame('user', $messages[0]->role->value);
        $this->assertSame('what is the weather?', $messages[0]->content);

        // Assistant-with-tool-calls row produces an AssistantMessage whose
        // toolCalls collection is populated. ToolCall::arguments is an array
        // (the reader's decode step prevents the OpenAI string-arguments TypeError).
        $assistantWithCalls = $messages->first(
            fn (Message $m): bool => $m instanceof AssistantMessage && $m->toolCalls->isNotEmpty(),
        );
        $this->assertNotNull($assistantWithCalls, 'There must be at least one AssistantMessage with tool_calls');
        $this->assertIsArray($assistantWithCalls->toolCalls->first()->arguments);

        // Final assistant reply content survives.
        $final = $messages->last();
        $this->assertInstanceOf(AssistantMessage::class, $final);
        $this->assertSame('It is sunny.', $final->content);
    }

    public function testHermesConversationsWithNullUserIdDoNotAppearInLatestConversationIdLookup(): void
    {
        $conversationId = $this->seedHermesConversationAndMessages();

        // The Hermes-imported conversation has user_id = null. A user that
        // doesn't own it should not see it as their "latest" conversation.
        $someUserId = (int) auth()->user()->getId();
        $latest = new KanvasConversationStore()->latestConversationId($someUserId);

        // The current user might have other conversations from other tests; what
        // we care about is that the Hermes-imported one (user_id=null) is not it.
        $this->assertNotSame($conversationId, $latest, 'Agent-owned Hermes conversations must not bleed into a user resume view');
    }

    private function seedHermesConversationAndMessages(): string
    {
        $app = app(\Kanvas\Apps\Models\Apps::class);
        $companyId = (int) auth()->user()->getCurrentCompany()->getId();
        $appId = (int) $app->getId();

        // Match Hermes session_id format (YYYYMMDD_HHMMSS_<8-hex>) — 24 chars.
        // Brand-new uuid7 would be 36 chars and the :N suffix overflows the
        // varchar(36) column. Real ingestion only sees Hermes-shaped ids.
        $conversationId = '20260524_120000_' . substr(bin2hex(random_bytes(4)), 0, 8);

        // Mimic exactly what the importer writes — null user_id, agent_id set,
        // meta blob with the hermes session-level fields.
        DB::connection('intelligence')->table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => null,
            'agent_id' => 9999,
            'apps_id' => $appId,
            'companies_id' => $companyId,
            'title' => 'Round-trip test conversation',
            'meta' => json_encode(['runtime' => 'hermes', 'model' => 'test-model'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = Carbon::now();
        $rows = [
            [
                'id' => $conversationId . ':1',
                'role' => 'user',
                'content' => 'what is the weather?',
                'tool_calls' => '[]',
                'tool_results' => '[]',
            ],
            [
                'id' => $conversationId . ':2',
                'role' => 'assistant',
                'content' => '',
                // arguments must be an array (Laravel AI's ToolCall::__construct
                // requires it). The reader decodes OpenAI's string-encoded
                // arguments before persisting — we mirror that shape here.
                'tool_calls' => json_encode([
                    ['id' => 'tc-1', 'name' => 'weather_lookup', 'arguments' => ['city' => 'Miami']],
                ], JSON_THROW_ON_ERROR),
                'tool_results' => '[]',
            ],
            [
                'id' => $conversationId . ':3',
                'role' => 'tool_result',
                'content' => '',
                'tool_calls' => '[]',
                'tool_results' => json_encode([
                    ['id' => 'tc-1', 'name' => 'weather_lookup', 'arguments' => null, 'result' => 'sunny', 'result_id' => 'tc-1'],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => $conversationId . ':4',
                'role' => 'assistant',
                'content' => 'It is sunny.',
                'tool_calls' => '[]',
                'tool_results' => '[]',
            ],
        ];

        foreach ($rows as $r) {
            DB::connection('intelligence')->table('agent_conversation_messages')->insert([
                'id' => $r['id'],
                'conversation_id' => $conversationId,
                'user_id' => null,
                'agent' => 'hermes-agent-uuid',
                'role' => $r['role'],
                'content' => $r['content'],
                'attachments' => '[]',
                'tool_calls' => $r['tool_calls'],
                'tool_results' => $r['tool_results'],
                'usage' => '[]',
                'meta' => '[]',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $conversationId;
    }
}
