<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Connectors\OpenCode\Client;
use Kanvas\Connectors\OpenCode\OpenCodeHarness;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Override;
use Tests\TestCase;

/**
 * Shapes here are copied from a live opencode 2.0.16, not invented — see the tree's CLAUDE.md.
 */
class OpenCodeHarnessTest extends TestCase
{
    public function testPollMapsTokensNarrationModelsAndCursorFromTheApiShapes(): void
    {
        $tick = $this->harness()->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::IDLE, $tick->status);
        $this->assertSame(10, $tick->usage->inputTokens);
        $this->assertSame(5, $tick->usage->outputTokens);
        $this->assertSame(0, $tick->usage->reasoningTokens);
        $this->assertSame(15, $tick->usage->totalTokens());
        $this->assertSame(['hello'], $tick->narration);
        $this->assertSame(['gpt-4.1'], $tick->modelsObserved);
        $this->assertSame('cur_2', $tick->cursor);
        $this->assertSame(1000, $tick->lastMessageAt);
        $this->assertNull($tick->error);
        $this->assertSame([], $tick->permissions);
        $this->assertSame([], $tick->questions);
    }

    public function testNarrationIsSuppressedForMessagesAtOrBeforeTheStoredCursor(): void
    {
        $session = $this->taskSession();
        $session->last_message_at = 1000;

        $tick = $this->harness()->poll($session);

        $this->assertFalse($tick->hasNarration());
        $this->assertSame([], $tick->narration);
        // The marker must still advance to the newest message, or a later tick re-posts the transcript.
        $this->assertSame(1000, $tick->lastMessageAt);
    }

    public function testUsageSumsAcrossMessagesIncludingCacheAndReasoningTokens(): void
    {
        $tick = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(
                    createdAt: 1000,
                    text: 'first',
                    tokens: ['input' => 10, 'output' => 5, 'reasoning' => 3, 'cache' => ['read' => 7, 'write' => 2]]
                ),
                $this->assistantMessage(
                    createdAt: 2000,
                    text: 'second',
                    tokens: ['input' => 1, 'output' => 2, 'reasoning' => 4, 'cache' => ['read' => 8, 'write' => 6]],
                ),
            ]),
        ])->poll($this->taskSession());

        $this->assertSame(11, $tick->usage->inputTokens);
        $this->assertSame(7, $tick->usage->outputTokens);
        $this->assertSame(15, $tick->usage->cacheReadTokens);
        $this->assertSame(8, $tick->usage->cacheWriteTokens);
        $this->assertSame(7, $tick->usage->reasoningTokens);
        $this->assertSame(['first', 'second'], $tick->narration);
        $this->assertSame(2000, $tick->lastMessageAt);
    }

    public function testStatusIsAwaitingPermissionWhenAPermissionIsPending(): void
    {
        $tick = $this->harness([
            'permission' => ['data' => [[
                'id' => 'per_1',
                'action' => 'bash',
                'resources' => ['echo hi'],
                'source' => ['callID' => 'call_1'],
            ]]],
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::AWAITING_PERMISSION, $tick->status);
        $this->assertCount(1, $tick->permissions);

        $permission = $tick->permissions[0];
        $this->assertSame('per_1', $permission->id);
        $this->assertSame('bash', $permission->action);
        $this->assertSame(['echo hi'], $permission->resources);
        $this->assertSame('call_1', $permission->callId);
        $this->assertSame('bash: echo hi', $permission->describe());
    }

    public function testStatusIsAwaitingAnswerWhenAFormIsPending(): void
    {
        $tick = $this->harness([
            'form' => ['data' => [[
                'id' => 'frm_1',
                'sessionID' => 'ses_x',
                'title' => 'Which database?',
                'fields' => [['key' => 'db', 'title' => 'Name']],
            ]]],
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::AWAITING_ANSWER, $tick->status);
        $this->assertSame(['Which database? (Name)'], $tick->questions);
    }

    /**
     * A completed assistant message does not mean the turn ended — the agent is routinely between
     * steps. Only the `idle` message closes it.
     */
    public function testStatusIsRunningUntilAnIdleMessageArrives(): void
    {
        $tick = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(createdAt: 1000, text: 'wrote a file'),
            ], idle: null),
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::RUNNING, $tick->status);
    }

    public function testStatusIsStartingWhenNoAssistantMessageExistsYet(): void
    {
        $tick = $this->harness(['message' => $this->page([], idle: null)])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::STARTING, $tick->status);
        $this->assertNull($tick->lastMessageAt);
    }

    public function testStatusIsFailedWhenTheRunEndedOnANonSuccessOutcome(): void
    {
        $tick = $this->harness([
            'message' => $this->page(
                [$this->assistantMessage(createdAt: 1000, text: 'tried')],
                idle: ['type' => 'idle', 'time' => ['created' => 1200], 'outcome' => 'aborted']
            ),
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::FAILED, $tick->status);
        $this->assertSame('the run ended as "aborted"', $tick->error);
    }

    public function testModelsObservedListsEveryDistinctModelThatSpoke(): void
    {
        $tick = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(createdAt: 1000, text: 'a', model: 'gpt-4.1'),
                $this->assistantMessage(createdAt: 2000, text: 'b', model: 'gpt-4.1'),
                $this->assistantMessage(createdAt: 3000, text: 'c', model: 'claude-haiku'),
            ]),
        ])->poll($this->taskSession());

        $this->assertSame(['gpt-4.1', 'claude-haiku'], $tick->modelsObserved);
        $this->assertSame('claude-haiku', $tick->substitutedModel(['gpt-4.1']));
    }

    /**
     * The field that makes one container able to hold many tasks. Without it every session shares the
     * runtime's own directory — same tree, same project config, no isolation.
     */
    public function testCreateSessionPinsTheSessionToItsOwnDirectory(): void
    {
        $sent = [];
        $client = new Client($this->recordingTransport($sent));

        $client->createSession(['providerID' => 'oai', 'id' => 'gpt-4.1'], '/workspaces/task-a');

        $this->assertSame('/workspaces/task-a', $sent[0]['body']['location']['directory']);
        $this->assertSame(['providerID' => 'oai', 'id' => 'gpt-4.1'], $sent[0]['body']['model']);
    }

    public function testAPageThatReturnsNothingDoesNotAdvanceTheCursor(): void
    {
        $sent = [];
        $client = new Client($this->recordingTransport($sent, ['data' => [], 'cursor' => ['next' => 'cur_9']]));

        $page = $client->messages('ses_x', 'cur_3');

        $this->assertSame('cur_3', $page['cursor']);
    }

    private function taskSession(): AgentTaskSession
    {
        $session = new AgentTaskSession();
        $session->external_session_id = 'ses_x';
        $session->harness = 'opencode';
        $session->status = HarnessStatusEnum::RUNNING->value;
        $session->session_data_path = '/workspaces/task-a';

        return $session;
    }

    /**
     * @param array<string, array<string, mixed>> $overrides keyed by the path segment opencode serves
     */
    private function harness(array $overrides = []): OpenCodeHarness
    {
        $payloads = array_merge([
            'message' => $this->page([$this->assistantMessage(createdAt: 1000, text: 'hello')]),
            'permission' => ['data' => []],
            'form' => ['data' => []],
        ], $overrides);

        return new OpenCodeHarness(new Client($this->transport($payloads)));
    }

    /**
     * @param list<array<string, mixed>>   $messages
     * @param array<string, mixed>|null    $idle     the turn-closing message; null leaves it open
     * @return array<string, mixed>
     */
    private function page(array $messages, ?array $idle = ['type' => 'idle', 'time' => ['created' => 1200], 'outcome' => 'succeeded']): array
    {
        return [
            'data' => $idle === null ? $messages : [...$messages, $idle],
            'cursor' => ['previous' => null, 'next' => 'cur_2'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $payloads
     */
    private function transport(array $payloads): HarnessTransport
    {
        return new class ($payloads) implements HarnessTransport {
            /**
             * @param array<string, array<string, mixed>> $payloads
             */
            public function __construct(
                private readonly array $payloads,
            ) {
            }

            #[Override]
            public function request(
                string $method,
                string $path,
                ?array $body = null,
                int $timeout = 30
            ): array {
                // v2 paginates, so the path carries a query string the suffix match must ignore.
                $route = explode('?', $path)[0];

                foreach ($this->payloads as $suffix => $payload) {
                    if (str_ends_with($route, '/' . $suffix)) {
                        return $payload;
                    }
                }

                return [];
            }
        };
    }

    /**
     * @param list<array{method: string, path: string, body: array<string, mixed>|null}> $sent
     * @param array<string, mixed>                                                       $response
     */
    private function recordingTransport(array &$sent, array $response = ['data' => ['id' => 'ses_new']]): HarnessTransport
    {
        return new class ($sent, $response) implements HarnessTransport {
            /**
             * @param list<array{method: string, path: string, body: array<string, mixed>|null}> $sent
             * @param array<string, mixed>                                                       $response
             */
            public function __construct(
                private array &$sent,
                private readonly array $response,
            ) {
            }

            #[Override]
            public function request(
                string $method,
                string $path,
                ?array $body = null,
                int $timeout = 30
            ): array {
                $this->sent[] = ['method' => $method, 'path' => $path, 'body' => $body];

                return $this->response;
            }
        };
    }

    /**
     * @param array<string, mixed>|null $tokens
     * @return array<string, mixed>
     */
    private function assistantMessage(
        int $createdAt,
        string $text,
        string $model = 'gpt-4.1',
        ?array $tokens = null
    ): array {
        return [
            'type' => 'assistant',
            'model' => ['id' => $model, 'providerID' => 'oai'],
            'tokens' => $tokens ?? ['input' => 10, 'output' => 5, 'reasoning' => 0, 'cache' => ['read' => 0, 'write' => 0]],
            'cost' => 0,
            'time' => ['created' => $createdAt, 'completed' => $createdAt + 100],
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }
}
