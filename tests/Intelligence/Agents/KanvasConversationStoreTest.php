<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Laravel\KanvasGenericLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\ReadsAgentConversationRows;

class KanvasConversationStoreTest extends TestCase
{
    use ReadsAgentConversationRows;

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

    public function testTheAssistantTurnBackfillsAgentIdFromKanvasLaravelAgent(): void
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

        // Mirror Laravel AI's RememberConversation middleware: the conversation opens with NULL agent_id,
        // the user message carries only the agent CLASS, and the assistant turn is the first call that
        // carries the agent instance.
        $conversationId = $store->storeConversation($user->getMorphClass(), $user->getId(), 'middleware-flow-test');

        $rowBefore = $this->conversationRow($conversationId);
        $this->assertNull($rowBefore->agent_id);
        $this->assertSame($user->getMorphClass(), $rowBefore->participant_type);
        $this->assertSame($user->getId(), (int) $rowBefore->participant_id);
        $this->assertSame($user->getId(), (int) $rowBefore->user_id);

        $userMessageId = $store->storeUserMessage(
            $conversationId,
            $user->getMorphClass(),
            $user->getId(),
            $handler::class,
            new UserMessage('hello agent'),
        );

        $userRow = $this->messageRow($userMessageId);
        $this->assertSame($user->getId(), (int) $userRow->user_id);
        $this->assertSame($user->getMorphClass(), $userRow->participant_type);
        $this->assertSame('completed', $userRow->status);
        $this->assertSame('[]', $userRow->steps);
        $this->assertNull($this->conversationRow($conversationId)->agent_id);

        $assistantMessageId = $store->storeAssistantMessage(
            $conversationId,
            $user->getMorphClass(),
            $user->getId(),
            $this->prompt($handler),
            new AgentResponse('inv-1', 'hi there', new TextUsage(3, 4), new Meta()),
        );

        $this->assertSame($agent->getId(), (int) $this->conversationRow($conversationId)->agent_id);

        $assistantRow = $this->messageRow($assistantMessageId);
        $this->assertSame($user->getId(), (int) $assistantRow->user_id);
        $this->assertSame('hi there', json_decode($assistantRow->steps, true)[0]['content']);
        $this->assertSame('completed', $assistantRow->status);
        $this->assertSame(['input_tokens' => 3, 'output_tokens' => 4], array_intersect_key(json_decode($assistantRow->usage, true), ['input_tokens' => 1, 'output_tokens' => 1]));
    }

    public function testLatestConversationIdIsScopedToTheAgentClass(): void
    {
        $user = auth()->user();
        $store = new KanvasConversationStore();
        $type = $user->getMorphClass();

        $withA = $store->storeConversation($type, $user->getId(), 'with A');
        $store->storeUserMessage(
            $withA,
            $type,
            $user->getId(),
            'Agents\\A',
            new UserMessage('hi A'),
        );
        $withB = $store->storeConversation($type, $user->getId(), 'with B');
        $store->storeUserMessage(
            $withB,
            $type,
            $user->getId(),
            'Agents\\B',
            new UserMessage('hi B'),
        );

        $this->assertSame($withA, $store->latestConversationId($type, $user->getId(), 'Agents\\A'));
        $this->assertSame($withB, $store->latestConversationId($type, $user->getId(), 'Agents\\B'));
        $this->assertNull($store->latestConversationId($type, $user->getId(), 'Agents\\Never'));
    }

    public function testStoreConversationHonoursThePreGeneratedId(): void
    {
        $user = auth()->user();
        $id = (string) Str::uuid7();

        $stored = new KanvasConversationStore()->storeConversation(
            $user->getMorphClass(),
            $user->getId(),
            'pre-keyed',
            $id,
        );

        $this->assertSame($id, $stored);
        $this->assertSame('pre-keyed', $this->conversationRow($id)->title);
    }

    public function testAFailedTurnIsStoredWithItsError(): void
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
        $conversationId = $store->storeConversation($user->getMorphClass(), $user->getId(), 'failing');

        $messageId = $store->storeAssistantMessage(
            $conversationId,
            $user->getMorphClass(),
            $user->getId(),
            $this->prompt($handler),
            new AgentResponse('inv-9', '', new TextUsage(), new Meta()),
            new RuntimeException('Provider connection failed'),
        );

        $row = $this->messageRow($messageId);
        $this->assertSame('failed', $row->status);
        $this->assertSame('Provider connection failed', json_decode($row->meta, true)['error']);
    }

    public function testAPausedTurnResumesIntoTheSameRowAndADenialIsRecorded(): void
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
        $type = $user->getMorphClass();
        $conversationId = $store->storeConversation($type, $user->getId(), 'approvals');

        $pausedId = $store->storeAssistantMessage(
            $conversationId,
            $type,
            $user->getId(),
            $this->prompt($handler),
            $this->pausedOn('call-1', ['path' => 'x']),
        );

        $pausedRow = $this->messageRow($pausedId);
        $this->assertSame('paused', $pausedRow->status);
        $call = json_decode($pausedRow->steps, true)[0]['tool_calls'][0];
        $this->assertSame('Destructive.', $call['approval_reason']);
        $this->assertArrayNotHasKey('result', $call);
        $this->assertCount(1, $store->pendingApprovalsFor($conversationId));

        $store->storeApprovalResults($conversationId, [new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'deleted')]);

        $resumedId = $store->storeAssistantMessage(
            $conversationId,
            $type,
            $user->getId(),
            $this->prompt($handler, Decisions::from(['call-1' => true])),
            new AgentResponse('inv-2', 'Deleted.', new TextUsage(5, 5), new Meta()),
        );

        $this->assertSame($pausedId, $resumedId, 'a resume folds into the row it paused on');
        $resumedRow = $this->messageRow($resumedId);
        $this->assertSame('completed', $resumedRow->status);
        $this->assertSame('Deleted.', $resumedRow->content);
        $this->assertSame('deleted', json_decode($resumedRow->steps, true)[0]['tool_calls'][0]['result']);
        $this->assertCount(0, $store->pendingApprovalsFor($conversationId));
        $this->assertSame(1, DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)->where('role', 'assistant')->count());

        $deniedId = $store->storeAssistantMessage(
            $conversationId,
            $type,
            $user->getId(),
            $this->prompt($handler),
            $this->pausedOn('call-2', ['path' => 'y']),
        );
        $store->storeApprovalResults($conversationId, [new ToolResult('call-2', 'delete_file', ['path' => 'y'], 'Denied by the user.', denied: true)]);
        $store->storeAssistantMessage(
            $conversationId,
            $type,
            $user->getId(),
            $this->prompt($handler, Decisions::from(['call-2' => false])),
            new AgentResponse('inv-3', 'Understood, leaving it.', new TextUsage(), new Meta()),
        );

        $deniedCall = json_decode($this->messageRow($deniedId)->steps, true)[0]['tool_calls'][0];
        $this->assertTrue($deniedCall['denied']);
        $this->assertSame('completed', $this->messageRow($deniedId)->status);
    }

    private function prompt(KanvasGenericLaravelAgent $handler, ?Decisions $decisions = null): AgentPrompt
    {
        return new AgentPrompt(
            agent: $handler,
            prompt: 'hello agent',
            attachments: new Collection([]),
            provider: Mockery::mock(TextProvider::class),
            model: 'fake-model',
            approvalDecisions: $decisions,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function pausedOn(string $callId, array $arguments): AgentResponse
    {
        return AgentResponse::fakeWithPendingApprovals([new PendingApproval($callId, 'delete_file', $arguments, 'Destructive.')])
            ->withToolCallsAndResults(new Collection([new ToolCall($callId, 'delete_file', $arguments)]), new Collection([]));
    }

    /**
     * Per-turn usage and tool telemetry live only in agent_conversation_messages, never in a Social message.
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

        $steps = json_decode($assistant->steps, true);
        $this->assertCount(2, $steps);
        $this->assertSame('get_lead_ref', $steps[0]['tool_calls'][0]['name']);
        $this->assertSame('{"lead_id":700015}', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame('Of course you can reschedule.', $steps[1]['content']);
        $this->assertSame('completed', $assistant->status);
        $this->assertSame($user->getMorphClass(), $assistant->participant_type);
        $this->assertSame($user->getId(), (int) $assistant->participant_id);
    }

    public function testParticipantIsThePersonOfAPeopleSessionThenTheAgentWhenItActsAsItselfThenTheUser(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $peopleSession = Session::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agents_id' => $agent->getId(),
            'uuid' => (string) Str::uuid(),
            'canal_id' => '',
            'entity_namespace' => People::class,
            'entity_id' => $people->getId(),
            'user' => [],
            'content' => [],
        ]);

        $this->assertTrue($people->is(KanvasConversationStore::participantFor($peopleSession, $user, $agent)));
        $this->assertTrue($agent->is(KanvasConversationStore::participantFor(null, $user, $agent)), 'the agent\'s own user acting = agent-owned');

        // The authenticated test user doubles as the company's AI agent user in CI, so the human here is a
        // fresh user that is neither an agent's dedicated user nor a company AI user.
        $human = Users::factory()->create();
        $otherAgent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
        $this->assertTrue($human->is(KanvasConversationStore::participantFor(null, $human, $otherAgent)));
        $this->assertTrue(
            $human->is(KanvasConversationStore::participantFor($peopleSession, $human, $otherAgent)),
            'a human chatting owns the conversation even when the session is keyed to a People record',
        );
        $this->assertTrue($human->is(KanvasConversationStore::participantFor(null, $human)));
    }

    public function testLogTurnWithAPeopleParticipantKeepsTheActingUserOnTheRows(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $sessionId = (string) Str::uuid();

        new KanvasConversationStore()->logTurn(
            userId: $user->getId(),
            sessionId: $sessionId,
            agentClass: 'Test\\Stub\\ShopAgent',
            userMessage: 'do you ship to DR?',
            assistantResponse: 'Yes, we do.',
            agentId: $agent->getId(),
            participant: $people,
        );

        $conversation = DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $sessionId)
            ->first();
        $this->assertSame($people->getMorphClass(), $conversation->participant_type);
        $this->assertSame($people->getId(), (int) $conversation->participant_id);
        $this->assertSame($user->getId(), (int) $conversation->user_id);

        $rows = DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($people->getMorphClass(), $row->participant_type);
            $this->assertSame($people->getId(), (int) $row->participant_id);
            $this->assertSame($user->getId(), (int) $row->user_id);
        }
    }

    public function testAnAnonymousConversationIsClaimedByThePersonOnTheFirstTurnAfterPromotion(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $sessionId = (string) Str::uuid();

        $store = new KanvasConversationStore();

        // Anonymous shopper: the session has no People yet, so the row opens without a participant.
        $anonymous = $this->conversationRow($store->insertConversation(
            $user->getId(),
            $agent->getId(),
            $app->getId(),
            $company->getId(),
            $sessionId,
        ));
        DB::connection('intelligence')->table('agent_conversations')->where('id', $anonymous->id)
            ->update(['participant_type' => null, 'participant_id' => null]);

        $resolved = $store->conversationForSession(
            $user->getId(),
            $sessionId,
            $agent->getId(),
            $app->getId(),
            $company->getId(),
            $people,
        );

        $this->assertSame($anonymous->id, $resolved, 'the same thread, not a second row');
        $claimed = $this->conversationRow($resolved);
        $this->assertSame($people->getMorphClass(), $claimed->participant_type);
        $this->assertSame($people->getId(), (int) $claimed->participant_id);
        $this->assertSame($user->getId(), (int) $claimed->user_id, 'the acting user stays');
    }

    public function testStoreConversationForAnAgentParticipantActsThroughTheAgentUser(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $conversationId = new KanvasConversationStore()->storeConversation($agent->getMorphClass(), $agent->getId(), 'agent-owned');

        $row = DB::connection('intelligence')->table('agent_conversations')->where('id', $conversationId)->first();
        $this->assertSame($agent->getMorphClass(), $row->participant_type);
        $this->assertSame($agent->getId(), (int) $row->participant_id);
        $this->assertSame($agent->getId(), (int) $row->agent_id);
        $this->assertSame($user->getId(), (int) $row->user_id);
    }
}
