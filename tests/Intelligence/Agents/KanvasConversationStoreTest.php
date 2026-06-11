<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\KanvasGenericLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Mockery;
use Tests\TestCase;

class KanvasConversationStoreTest extends TestCase
{
    public function testLogTurnPersistsAgentIdOnConversation(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $sessionId = (string) Str::uuid();

        new KanvasConversationStore()->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\AgentHandler',
            userMessage: 'hello',
            assistantResponse: 'world',
            agentId: $agent->getId(),
        );

        $row = DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $sessionId)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($agent->getId(), (int) $row->agent_id);
        $this->assertSame($user->getId(), (int) $row->user_id);
    }

    public function testTwoAgentsSharingSessionIdGetSeparateConversations(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentA = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $agentB = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $sessionId = (string) Str::uuid();
        $store = new KanvasConversationStore();

        $store->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\HandlerA',
            userMessage: 'hi from A',
            assistantResponse: 'reply A',
            agentId: $agentA->getId(),
        );

        $store->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\HandlerB',
            userMessage: 'hi from B',
            assistantResponse: 'reply B',
            agentId: $agentB->getId(),
        );

        $rows = DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $sessionId)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->orderBy('agent_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(
            [$agentA->getId(), $agentB->getId()],
            $rows->pluck('agent_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function testSameAgentSameSessionReusesConversation(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $sessionId = (string) Str::uuid();
        $store = new KanvasConversationStore();

        $store->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\Handler',
            userMessage: 'turn 1 user',
            assistantResponse: 'turn 1 assistant',
            agentId: $agent->getId(),
        );

        $store->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\Handler',
            userMessage: 'turn 2 user',
            assistantResponse: 'turn 2 assistant',
            agentId: $agent->getId(),
        );

        $conversationCount = DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $sessionId)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('agent_id', $agent->getId())
            ->count();

        $this->assertSame(1, $conversationCount);

        $conversationId = DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $sessionId)
            ->where('agent_id', $agent->getId())
            ->value('id');

        $messageCount = DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->count();

        $this->assertSame(4, $messageCount);
    }

    public function testLogTurnWithoutAgentIdKeepsAgentIdNullForLegacyCompat(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $sessionId = (string) Str::uuid();

        new KanvasConversationStore()->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\LegacyHandler',
            userMessage: 'legacy hello',
            assistantResponse: 'legacy reply',
        );

        $row = DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $sessionId)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->agent_id);
    }

    public function testLinkedToAgentScopeHidesLegacyNullRows(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $store = new KanvasConversationStore();
        $legacySessionId = (string) Str::uuid();
        $taggedSessionId = (string) Str::uuid();

        $store->logTurn(
            userId: $user->getId(),
            sessionId: $legacySessionId,
            agentClass: 'Test\\Stub\\LegacyHandler',
            userMessage: 'legacy',
            assistantResponse: 'legacy reply',
        );

        $store->logTurn(
            userId: $user->getId(),
            sessionId: $taggedSessionId,
            agentClass: 'Test\\Stub\\Handler',
            userMessage: 'tagged',
            assistantResponse: 'tagged reply',
            agentId: $agent->getId(),
        );

        $visibleIds = AgentConversation::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->linkedToAgent()
            ->whereIn('title', [$legacySessionId, $taggedSessionId])
            ->pluck('title')
            ->all();

        $this->assertSame([$taggedSessionId], $visibleIds);
    }

    public function testStoreUserMessageBackfillsAgentIdFromKanvasLaravelAgent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $handler = new KanvasGenericLaravelAgent();
        $handler->setConfiguration($agent);

        $store = new KanvasConversationStore();

        // Mirror Laravel AI's RememberConversation middleware: the interface
        // creates the conversation with NULL agent_id (no prompt in scope),
        // then storeUserMessage carries the agent via $prompt->agent.
        $conversationId = $store->storeConversation($user->getId(), 'middleware-flow-test');

        $rowBefore = DB::connection('intelligence')->table('agent_conversations')
            ->where('id', $conversationId)
            ->first();
        $this->assertNull($rowBefore->agent_id);

        $prompt = new AgentPrompt(
            agent: $handler,
            prompt: 'hello agent',
            attachments: new Collection([]),
            provider: Mockery::mock(TextProvider::class),
            model: 'fake-model',
        );

        $store->storeUserMessage($conversationId, $user->getId(), $prompt);

        $rowAfter = DB::connection('intelligence')->table('agent_conversations')
            ->where('id', $conversationId)
            ->first();
        $this->assertSame($agent->getId(), (int) $rowAfter->agent_id);
    }

    /**
     * After the SalesAssistKanvasMessageHistory double-write removal, the per-turn
     * usage + tool telemetry is no longer written as a visible social message — it
     * lives ONLY here, in agent_conversation_messages. Guard that it still lands.
     */
    public function testLogTurnPersistsUsageAndToolTelemetryOnAssistantMessage(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $sessionId = (string) Str::uuid();
        $usage = ['input_tokens' => 24131, 'output_tokens' => 185];
        $toolCalls = [['name' => 'get_lead_ref', 'inputs' => ['lead_id' => 700015]]];
        $toolResults = [['name' => 'get_lead_ref', 'result' => '{"lead_id":700015}']];

        new KanvasConversationStore()->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\AgentHandler',
            userMessage: 'hi Sally, do i still have option to reschedule ?',
            assistantResponse: 'Of course you can reschedule.',
            agentId: $agent->getId(),
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            usage: $usage,
        );

        $conversation = DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $sessionId)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();
        $this->assertNotNull($conversation);

        $assistant = DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->first();

        $this->assertNotNull($assistant);
        $this->assertSame('Of course you can reschedule.', $assistant->content);
        $this->assertSame($usage, json_decode($assistant->usage, true));
        $this->assertSame($toolCalls, json_decode($assistant->tool_calls, true));
        $this->assertSame($toolResults, json_decode($assistant->tool_results, true));
    }
}
