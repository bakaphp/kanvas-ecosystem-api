<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCollectSessionTranscriptsAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\SessionTranscriptReader;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\ParsedMessage;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\ParsedSessionTranscript;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Override;
use Tests\TestCase;

class BaseCollectSessionTranscriptsActionTest extends TestCase
{
    public function testPersistsTranscriptAndIsIdempotent(): void
    {
        [$deployment, $sessionId] = $this->seedDeploymentAndSessionId();

        $reader = new FakeReader([
            $this->makeTranscript($sessionId, [
                $this->makeUserMessage(1, 'Hello there'),
                $this->makeAssistantMessage(2, 'Hi! How can I help?'),
                $this->makeAssistantToolCallMessage(3, 'lookup_user', 'tcid-1'),
                $this->makeToolResultMessage(4, 'tcid-1', 'lookup_user', '{"name":"Max"}'),
            ]),
        ]);

        $first = $this->runAction($deployment, $reader);
        $this->assertSame(4, $first, 'First run should persist 4 messages');

        // Re-run with the same reader — deterministic ids should absorb the replay.
        $second = $this->runAction($deployment, new FakeReader([
            $this->makeTranscript($sessionId, [
                $this->makeUserMessage(1, 'Hello there'),
                $this->makeAssistantMessage(2, 'Hi! How can I help?'),
                $this->makeAssistantToolCallMessage(3, 'lookup_user', 'tcid-1'),
                $this->makeToolResultMessage(4, 'tcid-1', 'lookup_user', '{"name":"Max"}'),
            ]),
        ]));
        $this->assertSame(0, $second, 'Replay should insert zero new rows');

        $convo = AgentConversation::query()->findOrFail($sessionId);
        $this->assertSame($deployment->agent->getId(), $convo->agent_id);
        $this->assertSame('Test Session Title', $convo->title);
        $this->assertSame(4, $convo->messages()->count());

        $messages = $convo->messages()->orderBy('id')->get();
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('Hello there', $messages[0]->content);
        $this->assertSame('assistant', $messages[1]->role);

        // Tool-result row: content is empty/null (DB column is NOT NULL DEFAULT '',
        // so MySQL coerces a null write to ''), payload in tool_results per
        // Laravel AI rules. The KanvasConversationStore reader builds
        // ToolResultMessage(toolResults) and ignores the content column for tool
        // rows, so the Laravel AI invariant (content=null on ToolResultMessage)
        // is satisfied at read time regardless.
        $toolResult = $messages->firstWhere('role', 'tool_result');
        $this->assertNotNull($toolResult);
        $this->assertEmpty($toolResult->content, 'tool_result row should have empty content');
        $toolResults = $toolResult->tool_results;
        $this->assertIsArray($toolResults);
        $this->assertSame('tcid-1', $toolResults[0]['id']);
        $this->assertSame('{"name":"Max"}', $toolResults[0]['result']);

        // Watermark advanced to the highest runtime id.
        $this->assertSame(4, $convo->meta['runtime_last_message_id']);
    }

    public function testRoleMappingNormalizesToolAndFunctionToToolResult(): void
    {
        [$deployment, $sessionId] = $this->seedDeploymentAndSessionId();

        // The reader is the seam that does role mapping — simulate that
        // both 'tool' and 'function' upstream roles land as 'tool_result'.
        $reader = new FakeReader([
            $this->makeTranscript($sessionId, [
                $this->makeUserMessage(10, 'go'),
                $this->makeToolResultMessage(11, 'tc-a', 'fnA', 'first'),    // from 'tool'
                $this->makeToolResultMessage(12, 'tc-b', 'fnB', 'second'),   // from 'function'
            ]),
        ]);

        $this->runAction($deployment, $reader);

        $roles = AgentConversation::query()->findOrFail($sessionId)
            ->messages()->orderBy('id')->pluck('role')->all();
        $this->assertSame(['user', 'tool_result', 'tool_result'], $roles);
    }

    public function testEmptyTranscriptListIsNoOp(): void
    {
        [$deployment] = $this->seedDeploymentAndSessionId();

        $persisted = $this->runAction($deployment, new FakeReader([]));
        $this->assertSame(0, $persisted);
        $this->assertSame(0, AgentConversation::query()->where('agent_id', $deployment->agent->getId())->count());
    }

    public function testConversationUserIdFallsBackToAgentPersonaUser(): void
    {
        [$deployment, $sessionId] = $this->seedDeploymentAndSessionId();
        $agent = $deployment->agent;

        // Reader doesn't resolve a runtime user — rawMeta is empty by default.
        // The action should attribute the conversation to the agent's persona
        // user (agents.user_id) so it isn't orphaned from any user view.
        $this->runAction($deployment, new FakeReader([
            $this->makeTranscript($sessionId, [
                $this->makeUserMessage(1, 'first turn'),
            ]),
        ]));

        $convo = AgentConversation::query()->findOrFail($sessionId);
        $this->assertSame((int) $agent->user_id, $convo->user_id);
    }

    public function testConversationUserIdPrefersReaderResolvedOverPersonaFallback(): void
    {
        [$deployment, $sessionId] = $this->seedDeploymentAndSessionId();
        $agent = $deployment->agent;

        // Pick any user id that isn't the agent's persona. agent_conversations.user_id
        // has no FK constraint (verified in the migration — foreignId() without
        // ->constrained()), so any positive int works for this assertion.
        $resolvedUserId = (int) $agent->user_id + 999;
        $this->assertNotSame((int) $agent->user_id, $resolvedUserId, 'test fixture sanity: resolved user must differ from persona user');

        $transcript = new ParsedSessionTranscript(
            sessionId: $sessionId,
            title: 'Resolved-user attribution',
            source: 'slack',
            model: null,
            systemPrompt: null,
            parentSessionId: null,
            startedAt: Carbon::now()->subMinute(),
            endedAt: null,
            endReason: null,
            messageCount: 1,
            toolCallCount: null,
            inputTokens: null,
            outputTokens: null,
            cacheReadTokens: null,
            cacheWriteTokens: null,
            reasoningTokens: null,
            estimatedCostUsd: null,
            actualCostUsd: null,
            handoffState: null,
            sourceReader: 'state.db',
            messages: [$this->makeUserMessage(1, 'first turn')],
            rawMeta: ['resolved_user_id' => $resolvedUserId],
        );

        $this->runAction($deployment, new FakeReader([$transcript]));

        $convo = AgentConversation::query()->findOrFail($sessionId);
        $this->assertSame($resolvedUserId, $convo->user_id, 'reader-resolved user should win over agent persona');
    }

    /**
     * @return array{0: AgentDeployment, 1: string}
     */
    private function seedDeploymentAndSessionId(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
                'is_active' => true,
            ]);

        // The base action only reads $deployment->agent. We don't need a real
        // SSH-reachable machine for this test — the fake reader bypasses it.
        $deployment = new AgentDeployment();
        $deployment->setRelation('agent', $agent);

        $sessionId = '20260524_120000_' . substr(uniqid(), -8);

        return [$deployment, $sessionId];
    }

    private function runAction(AgentDeployment $deployment, SessionTranscriptReader $reader): int
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new FakeAction($deployment, $app, $company, null, $reader)->execute();
    }

    /**
     * @param list<ParsedMessage> $messages
     */
    private function makeTranscript(string $sessionId, array $messages): ParsedSessionTranscript
    {
        return new ParsedSessionTranscript(
            sessionId: $sessionId,
            title: 'Test Session Title',
            source: 'slack',
            model: 'test-model',
            systemPrompt: null,
            parentSessionId: null,
            startedAt: Carbon::now()->subMinutes(10),
            endedAt: null,
            endReason: null,
            messageCount: count($messages),
            toolCallCount: null,
            inputTokens: 100,
            outputTokens: 50,
            cacheReadTokens: null,
            cacheWriteTokens: null,
            reasoningTokens: null,
            estimatedCostUsd: 0.001,
            actualCostUsd: null,
            handoffState: null,
            sourceReader: 'state.db',
            messages: $messages,
            rawMeta: [],
        );
    }

    private function makeUserMessage(int $id, string $content): ParsedMessage
    {
        return new ParsedMessage(
            runtimeMessageId: $id,
            role: 'user',
            content: $content,
            toolCalls: null,
            toolResults: null,
            toolCallId: null,
            toolName: null,
            finishReason: null,
            tokenCount: null,
            reasoningContent: null,
            reasoningDetails: null,
            codexReasoningItems: null,
            codexMessageItems: null,
            occurredAt: Carbon::now()->subMinutes(10 - $id),
            extraMeta: [],
        );
    }

    private function makeAssistantMessage(int $id, string $content): ParsedMessage
    {
        return new ParsedMessage(
            runtimeMessageId: $id,
            role: 'assistant',
            content: $content,
            toolCalls: null,
            toolResults: null,
            toolCallId: null,
            toolName: null,
            finishReason: 'stop',
            tokenCount: 25,
            reasoningContent: null,
            reasoningDetails: null,
            codexReasoningItems: null,
            codexMessageItems: null,
            occurredAt: Carbon::now()->subMinutes(10 - $id),
            extraMeta: [],
        );
    }

    private function makeAssistantToolCallMessage(int $id, string $toolName, string $toolCallId): ParsedMessage
    {
        return new ParsedMessage(
            runtimeMessageId: $id,
            role: 'assistant',
            content: '',
            toolCalls: [['id' => $toolCallId, 'function' => ['name' => $toolName, 'arguments' => '{}']]],
            toolResults: null,
            toolCallId: null,
            toolName: $toolName,
            finishReason: 'tool_calls',
            tokenCount: null,
            reasoningContent: null,
            reasoningDetails: null,
            codexReasoningItems: null,
            codexMessageItems: null,
            occurredAt: Carbon::now()->subMinutes(10 - $id),
            extraMeta: [],
        );
    }

    private function makeToolResultMessage(int $id, string $toolCallId, string $toolName, string $body): ParsedMessage
    {
        return new ParsedMessage(
            runtimeMessageId: $id,
            role: 'tool_result',
            content: null,
            toolCalls: null,
            toolResults: [[
                'id' => $toolCallId,
                'name' => $toolName,
                'arguments' => null,
                'result' => $body,
                'result_id' => $toolCallId,
            ]],
            toolCallId: $toolCallId,
            toolName: $toolName,
            finishReason: null,
            tokenCount: null,
            reasoningContent: null,
            reasoningDetails: null,
            codexReasoningItems: null,
            codexMessageItems: null,
            occurredAt: Carbon::now()->subMinutes(10 - $id),
            extraMeta: [],
        );
    }
}

/**
 * In-memory reader for the test — yields the transcripts it was constructed
 * with. Bypasses SSH entirely so we can exercise the base action's
 * persistence + idempotency logic without a real machine.
 */
final class FakeReader implements SessionTranscriptReader
{
    /** @param list<ParsedSessionTranscript> $transcripts */
    public function __construct(private readonly array $transcripts)
    {
    }

    #[Override]
    public function read(AgentDeployment $deployment, ?Carbon $since = null): iterable
    {
        foreach ($this->transcripts as $t) {
            yield $t;
        }
    }
}

/**
 * Test subclass that returns the fake reader. Mirrors how a real connector
 * (Hermes) provides its concrete reader by overriding reader().
 */
final class FakeAction extends BaseCollectSessionTranscriptsAction
{
    public function __construct(
        AgentDeployment $deployment,
        \Baka\Contracts\AppInterface $app,
        \Baka\Contracts\CompanyInterface $company,
        ?Carbon $since,
        private readonly SessionTranscriptReader $reader,
    ) {
        parent::__construct($deployment, $app, $company, $since);
    }

    #[Override]
    protected function reader(): SessionTranscriptReader
    {
        return $this->reader;
    }

    #[Override]
    protected function runtimeName(): string
    {
        return 'test';
    }
}
