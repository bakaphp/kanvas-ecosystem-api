<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Connectors\OpenCode\Client;
use Kanvas\Connectors\OpenCode\OpenCodeHarness;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Override;
use Tests\TestCase;

/**
 * Shapes here are copied from a live opencode, not invented — the `fixtures/opencode-2.2.0` files are
 * verbatim `/message`, `/permission` and `/form` responses from the image the Dockerfile builds; the
 * inline ones are trimmed from the same captures. See the tree's CLAUDE.md.
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
        $this->assertSame('Which database? (Name)', $tick->questions[0]->describe());
        // The id is the whole point: without it nothing can answer the form and the job dies at the
        // session timeout with its work thrown away.
        $this->assertSame('frm_1', $tick->questions[0]->id);
    }

    /** A form with no id cannot be answered by anything, so parking the session on it would strand it. */
    public function testAFormWithNoIdIsNotTreatedAsAQuestion(): void
    {
        $tick = $this->harness([
            'form' => ['data' => [[
                'sessionID' => 'ses_x',
                'title' => 'Which database?',
                'fields' => [['key' => 'db', 'title' => 'Name']],
            ]]],
        ])->poll($this->taskSession());

        $this->assertSame([], $tick->questions);
        $this->assertNotSame(HarnessStatusEnum::AWAITING_ANSWER, $tick->status);
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
                idle: ['type' => 'idle', 'time' => ['created' => 1200], 'outcome' => 'interrupted']
            ),
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::FAILED, $tick->status);
        $this->assertSame('the run ended as "interrupted"', $tick->error);
    }

    /**
     * Captured from 2.0.16 against a provider answering 402: the idle is bare, the error sits on the
     * assistant message it ended.
     */
    public function testAProviderErrorOnTheAssistantMessageIsSurfacedWhenTheIdleCarriesNone(): void
    {
        $tick = $this->harness([
            'message' => $this->page(
                [
                    $this->assistantMessage(createdAt: 1000, text: 'editing StepOne.tsx'),
                    $this->assistantMessage(
                        createdAt: 1100,
                        text: '',
                        error: ['type' => 'provider.quota', 'message' => 'This request requires more credits', 'status' => 402]
                    ),
                ],
                idle: ['type' => 'idle', 'time' => ['created' => 1200], 'outcome' => 'failed']
            ),
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::FAILED, $tick->status);
        $this->assertSame('provider.quota (HTTP 402): This request requires more credits', $tick->error);
    }

    public function testAnErrorOnTheIdleMessageWinsOverTheAssistantMessage(): void
    {
        $tick = $this->harness([
            'message' => $this->page(
                [$this->assistantMessage(createdAt: 1000, text: '', error: ['type' => 'provider.quota', 'message' => 'older'])],
                idle: [
                    'type' => 'idle',
                    'time' => ['created' => 1200],
                    'outcome' => 'failed',
                    'error' => ['type' => 'unknown', 'message' => 'Failed to drain Session'],
                ]
            ),
        ])->poll($this->taskSession());

        $this->assertSame('unknown: Failed to drain Session', $tick->error);
    }

    public function testAResumedTurnDoesNotReportTheErrorOfTheTurnItsPauseAborted(): void
    {
        $session = $this->taskSession();
        $session->turn_offset = 1;

        $tick = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(createdAt: 1000, text: '', error: ['type' => 'provider.quota', 'message' => 'no credits']),
                ['type' => 'idle', 'time' => ['created' => 1100], 'outcome' => 'failed'],
                $this->assistantMessage(createdAt: 2000, text: 'resumed'),
                ['type' => 'idle', 'time' => ['created' => 2100], 'outcome' => 'interrupted'],
            ], idle: null),
        ])->poll($session);

        $this->assertSame(HarnessStatusEnum::FAILED, $tick->status);
        $this->assertSame('the run ended as "interrupted"', $tick->error);
    }

    /**
     * The endpoint caps a page at 100. One page misses a long run's closing `idle` — so it can only end
     * at the time limit — and every token after message 100.
     */
    public function testPollFollowsEveryPageSoALongRunStillFinishes(): void
    {
        $firstPage = array_map(
            fn (int $i): array => $this->assistantMessage(createdAt: 1000 + $i, text: 'step ' . $i),
            range(1, 100)
        );

        $harness = new OpenCodeHarness(new Client($this->pagedTransport([
            '' => ['data' => $firstPage, 'cursor' => ['next' => 'cur_2']],
            'cur_2' => [
                'data' => [
                    $this->assistantMessage(createdAt: 2000, text: 'done'),
                    ['type' => 'idle', 'time' => ['created' => 2100], 'outcome' => 'succeeded'],
                ],
                'cursor' => ['next' => 'cur_3'],
            ],
        ])));

        $tick = $harness->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::IDLE, $tick->status);
        $this->assertSame(1010, $tick->usage->inputTokens);
        $this->assertSame('cur_3', $tick->cursor);
    }

    /**
     * Pausing at a limit interrupts the turn. Once resumed, that old `interrupted` idle must not be read as the
     * outcome of the new turn, or the run is failed on the first tick after it was told to continue.
     */
    public function testAResumedSessionIgnoresTheTurnItsPauseAborted(): void
    {
        $session = $this->taskSession();
        $session->turn_offset = 1;

        $tick = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(createdAt: 1000, text: 'working'),
                ['type' => 'idle', 'time' => ['created' => 1100], 'outcome' => 'interrupted'],
                $this->assistantMessage(createdAt: 2000, text: 'picking up where I stopped'),
            ], idle: null),
        ])->poll($session);

        $this->assertSame(HarnessStatusEnum::RUNNING, $tick->status);
        $this->assertNull($tick->error);
        $this->assertSame(1, $tick->closedTurns);
        $this->assertSame('picking up where I stopped', $tick->lastSaid);
    }

    /**
     * A coding chat posts `turnReply` after every turn. A turn that only ran tools must not repost the
     * previous turn's answer as if it were new.
     */
    public function testAMessageAfterATurnsIdleKeepsTheChatRunningInsteadOfDeliveringTheOldTurn(): void
    {
        $session = $this->taskSession();
        $session->turn_offset = 1;
        $prompt = fn (int $at): array => ['type' => 'user', 'time' => ['created' => $at], 'content' => [['type' => 'text', 'text' => 'run the app']]];

        $pending = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(createdAt: 1000, text: 'first answer'),
                ['type' => 'idle', 'time' => ['created' => 1100], 'outcome' => 'succeeded'],
                $this->assistantMessage(createdAt: 2000, text: 'second answer'),
                ['type' => 'idle', 'time' => ['created' => 2100], 'outcome' => 'succeeded'],
                $prompt(2200),
            ], idle: null),
        ])->poll($session);

        $this->assertSame(HarnessStatusEnum::RUNNING, $pending->status);

        $settled = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(createdAt: 1000, text: 'first answer'),
                ['type' => 'idle', 'time' => ['created' => 1100], 'outcome' => 'succeeded'],
                $this->assistantMessage(createdAt: 2000, text: 'second answer'),
                ['type' => 'idle', 'time' => ['created' => 2100], 'outcome' => 'succeeded'],
                $prompt(2200),
                $this->assistantMessage(createdAt: 2300, text: 'third answer'),
            ], idle: ['type' => 'idle', 'time' => ['created' => 2400], 'outcome' => 'succeeded']),
        ])->poll($session);

        $this->assertSame(HarnessStatusEnum::IDLE, $settled->status);
        $this->assertSame('third answer', $settled->turnReply, 'the reply is the newest turn, not the one the chat missed');
        $this->assertSame(3, $settled->closedTurns, 'delivering it puts the chat back in step');
    }

    public function testATurnAfterAStoppedOneRunsAndThenSucceedsInsteadOfReportingTheStop(): void
    {
        $session = $this->taskSession();
        $session->turn_offset = 1;
        $history = [
            $this->assistantMessage(createdAt: 1000, text: 'first answer'),
            ['type' => 'idle', 'time' => ['created' => 1100], 'outcome' => 'succeeded'],
            $this->assistantMessage(createdAt: 2000, text: 'working on it'),
            ['type' => 'idle', 'time' => ['created' => 2100], 'outcome' => 'interrupted'],
            ['type' => 'user', 'time' => ['created' => 2200], 'content' => [['type' => 'text', 'text' => 'now add a test']]],
        ];

        $running = $this->harness(['message' => $this->page($history, idle: null)])->poll($session);

        $this->assertSame(HarnessStatusEnum::RUNNING, $running->status, 'the stop belongs to the turn before');
        $this->assertNull($running->error);

        $done = $this->harness([
            'message' => $this->page(
                [...$history, $this->assistantMessage(createdAt: 2300, text: 'test added')],
                idle: ['type' => 'idle', 'time' => ['created' => 2400], 'outcome' => 'succeeded']
            ),
        ])->poll($session);

        $this->assertSame(HarnessStatusEnum::IDLE, $done->status);
        $this->assertNull($done->error);
        $this->assertSame('test added', $done->turnReply);
    }

    public function testTheTickSaysTheCurrentTurnsLatestStepAndWhenTheTurnStarted(): void
    {
        $session = $this->taskSession();
        $session->turn_offset = 1;
        $step = fn (int $at, string $tool, array $input): array => [
            ...$this->assistantMessage(createdAt: $at, text: ''),
            'content' => [['type' => 'tool', 'name' => $tool, 'state' => ['status' => 'running', 'input' => $input]]],
        ];

        $tick = $this->harness([
            'message' => $this->page([
                $step(1_000, 'shell', ['command' => 'git status']),
                ['type' => 'idle', 'time' => ['created' => 1_100], 'outcome' => 'succeeded'],
                $step(1_794_400_000_000, 'read', ['path' => '/workspaces/task-a/src/App.tsx']),
                $step(1_794_400_060_000, 'shell', ['command' => ".kanvas/device android\nsleep 5"]),
            ], idle: null),
        ])->poll($session);

        $this->assertSame('running `.kanvas/device android`', $tick->activity);
        $this->assertSame(1_794_400_000, $tick->turnStartedAt);

        $reading = $this->harness([
            'message' => $this->page([$step(1_794_400_000_000, 'read', ['path' => '/workspaces/task-a/src/App.tsx'])], idle: null),
        ])->poll($this->taskSession());

        $this->assertSame('reading `src/App.tsx`', $reading->activity);

        $patching = $this->harness([
            'message' => $this->page([
                $step(1_794_400_000_000, 'patch', ['patchText' => "*** Begin Patch\n*** Update File: src/Login.tsx\n@@\n-a\n+b\n*** End Patch"]),
            ], idle: null),
        ])->poll($this->taskSession());

        $this->assertSame('editing `src/Login.tsx`', $patching->activity);
    }

    public function testTurnReplyIsTheCurrentTurnsLastTextAndNullWhenItSaidNothing(): void
    {
        $session = $this->taskSession();
        $session->turn_offset = 1;

        $said = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(createdAt: 1000, text: 'first answer'),
                ['type' => 'idle', 'time' => ['created' => 1100], 'outcome' => 'succeeded'],
                $this->assistantMessage(createdAt: 2000, text: 'looking'),
                $this->assistantMessage(createdAt: 2100, text: 'second answer'),
            ], idle: ['type' => 'idle', 'time' => ['created' => 2200], 'outcome' => 'succeeded']),
        ])->poll($session);

        $silent = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(createdAt: 1000, text: 'first answer'),
                ['type' => 'idle', 'time' => ['created' => 1100], 'outcome' => 'succeeded'],
                $this->assistantMessage(createdAt: 2000, text: ''),
            ], idle: ['type' => 'idle', 'time' => ['created' => 2200], 'outcome' => 'succeeded']),
        ])->poll($session);

        $this->assertSame(HarnessStatusEnum::IDLE, $said->status);
        $this->assertSame('second answer', $said->turnReply);
        $this->assertNull($silent->turnReply);
        $this->assertSame('first answer', $silent->lastSaid);
    }

    public function testAResumedTurnThatClosesIsIdle(): void
    {
        $session = $this->taskSession();
        $session->turn_offset = 1;

        $tick = $this->harness([
            'message' => $this->page([
                $this->assistantMessage(createdAt: 1000, text: 'working'),
                ['type' => 'idle', 'time' => ['created' => 1100], 'outcome' => 'interrupted'],
                $this->assistantMessage(createdAt: 2000, text: 'finished'),
            ], idle: ['type' => 'idle', 'time' => ['created' => 2200], 'outcome' => 'succeeded']),
        ])->poll($session);

        $this->assertSame(HarnessStatusEnum::IDLE, $tick->status);
        $this->assertSame(2, $tick->closedTurns);
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

    public function testASessionThatSucceededOn220ReadsIdleWithItsTokensModelAndLastWords(): void
    {
        $tick = $this->harness([
            'message' => $this->fixture('success.messages'),
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::IDLE, $tick->status);
        $this->assertNull($tick->error);
        $this->assertSame(6, $tick->usage->inputTokens);
        $this->assertSame(39, $tick->usage->outputTokens);
        $this->assertSame(3900, $tick->usage->cacheReadTokens);
        $this->assertSame(3954, $tick->usage->cacheWriteTokens);
        $this->assertSame(['gpt-6-luna'], $tick->modelsObserved);
        $this->assertSame('done', $tick->lastSaid);
        $this->assertSame(1, $tick->closedTurns);
        $this->assertNotNull($tick->cursor);
    }

    /**
     * 2.2.0 puts the structured error on the idle itself (2.0.16 left the idle bare and the error on
     * the assistant message, which `testAProviderErrorOnTheAssistantMessageIsSurfacedWhenTheIdleCarriesNone`
     * still covers). Either way the reader learns it was the key, not the run.
     */
    public function testAProviderAuthFailureOn220IsReadFromTheIdle(): void
    {
        $tick = $this->harness([
            'message' => $this->fixture('provider-auth-failure.messages'),
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::FAILED, $tick->status);
        $this->assertStringStartsWith('provider.auth (HTTP 401): Incorrect API key provided', (string) $tick->error);
        $this->assertSame(0, $tick->usage->totalTokens());
    }

    /**
     * `/interrupt` closes the turn with `outcome: interrupted` and a bare idle; the step it cut carries
     * `{type: aborted, message: "Step interrupted"}`. Every Kanvas path that interrupts either stops
     * polling or moves `turn_offset` past this idle, so here it reads as the failure it is.
     */
    public function testAnInterruptedTurnOn220IsReportedFromTheStepItCut(): void
    {
        $tick = $this->harness([
            'message' => $this->fixture('interrupted.messages'),
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::FAILED, $tick->status);
        $this->assertSame('aborted: Step interrupted', $tick->error);
        $this->assertSame(3, $tick->closedTurns);
        $this->assertSame(21, $tick->usage->inputTokens);
        $this->assertSame(184, $tick->usage->reasoningTokens);
    }

    public function testAPendingFormOn220ParksTheSessionWithTheQuestionItAsks(): void
    {
        $tick = $this->harness([
            'message' => $this->fixture('form-pending.messages'),
            'form' => $this->fixture('form-pending.form'),
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::AWAITING_ANSWER, $tick->status);
        $this->assertCount(1, $tick->questions);
        $this->assertSame('frm_1a17abcc8001lKXKsmCJ1HID8x', $tick->questions[0]->id);
        $this->assertSame('Questions', $tick->questions[0]->title);
        $this->assertSame(['Color'], $tick->questions[0]->fields);
    }

    /**
     * The tool call a permission belongs to moved from `source.callID` to `source.id` in 2.2.0.
     */
    public function testAPendingPermissionOn220CarriesItsActionResourcesAndCallId(): void
    {
        $tick = $this->harness([
            'message' => $this->fixture('permission-pending.messages'),
            'permission' => $this->fixture('permission-pending.permission'),
        ])->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::AWAITING_PERMISSION, $tick->status);
        $this->assertCount(1, $tick->permissions);
        $this->assertSame('shell', $tick->permissions[0]->action);
        $this->assertSame(['ls -la'], $tick->permissions[0]->resources);
        $this->assertSame('call_GGNNYrU1ijBmjfKvH4pWlhDh', $tick->permissions[0]->callId);
    }

    /**
     * 2.2.0: `InvalidCursorError: Cursor cannot be combined with order`. The cursor is an opaque
     * `{id, order, direction}`, so it already knows the order; naming it again fails the request — and
     * with it every run longer than one page. The first page must still ask, because the default is
     * newest-first.
     */
    public function testThePageAfterTheFirstIsRequestedByCursorAlone(): void
    {
        $sent = [];
        $client = new Client($this->recordingTransport($sent, ['data' => [], 'cursor' => ['next' => null]]));

        $client->messages('ses_x');
        $client->messages('ses_x', 'cur_2');

        parse_str(explode('?', $sent[0]['path'], 2)[1], $first);
        parse_str(explode('?', $sent[1]['path'], 2)[1], $second);

        $this->assertSame(['order' => 'asc', 'limit' => '100'], $first);
        $this->assertSame(['cursor' => 'cur_2', 'limit' => '100'], $second);
    }

    public function testAStaleCursorMidReadRestartsFromTheTopOnce(): void
    {
        $firstPage = array_map(
            fn (int $i): array => $this->assistantMessage(createdAt: 1000 + $i, text: 'step ' . $i),
            range(1, 100)
        );
        $lastPage = [
            'data' => [
                $this->assistantMessage(createdAt: 2000, text: 'done'),
                ['type' => 'idle', 'time' => ['created' => 2100], 'outcome' => 'succeeded'],
            ],
            'cursor' => ['next' => 'cur_3'],
        ];
        $requested = [];

        $harness = new OpenCodeHarness(new Client($this->pagedTransport(
            ['' => ['data' => $firstPage, 'cursor' => ['next' => 'cur_2']], 'cur_2' => $lastPage],
            failOnce: 'cur_2',
            requested: $requested
        )));

        $tick = $harness->poll($this->taskSession());

        $this->assertSame(HarnessStatusEnum::IDLE, $tick->status);
        $this->assertSame(1010, $tick->usage->inputTokens, 'the restart must not double-count the first page');
        $this->assertSame(['', 'cur_2', '', 'cur_2'], $requested);
    }

    public function testASecondStaleCursorInOneReadIsAFault(): void
    {
        $firstPage = array_map(
            fn (int $i): array => $this->assistantMessage(createdAt: 1000 + $i, text: 'step ' . $i),
            range(1, 100)
        );
        $requested = [];

        $harness = new OpenCodeHarness(new Client($this->pagedTransport(
            ['' => ['data' => $firstPage, 'cursor' => ['next' => 'cur_2']]],
            failOnce: 'cur_2',
            requested: $requested,
            failAlways: true
        )));

        $this->expectException(HarnessTransportException::class);
        $this->expectExceptionMessage('InvalidCursorError');

        $harness->poll($this->taskSession());
    }

    /**
     * 409 `FormAlreadySettledError` — the form's twin of `PermissionNotFoundError`. Somebody answered
     * first; the session has moved on, which is not a failure of this answer.
     */
    public function testAnsweringAFormSomebodyAlreadySettledIsNotAnError(): void
    {
        $harness = new OpenCodeHarness(new Client($this->transport(
            ['form' => $this->fixture('form-pending.form')],
            failing: ['form/frm_1a17abcc8001lKXKsmCJ1HID8x/reply' => 'Harness returned HTTP 409 for POST /api/session/ses_x/form/frm_1a17abcc8001lKXKsmCJ1HID8x/reply: {"_tag":"FormAlreadySettledError","id":"frm_1a17abcc8001lKXKsmCJ1HID8x","message":"Form already settled"}']
        )));

        $harness->answerQuestion($this->taskSession(), 'frm_1a17abcc8001lKXKsmCJ1HID8x', 'Red');

        $this->addToAssertionCount(1);
    }

    public function testAnyOtherFormReplyFailureStillThrows(): void
    {
        $harness = new OpenCodeHarness(new Client($this->transport(
            ['form' => $this->fixture('form-pending.form')],
            failing: ['form/frm_1a17abcc8001lKXKsmCJ1HID8x/reply' => 'Harness returned HTTP 400 for POST reply: {"_tag":"FormInvalidAnswerError"}']
        )));

        $this->expectException(HarnessTransportException::class);
        $this->expectExceptionMessage('FormInvalidAnswerError');

        $harness->answerQuestion($this->taskSession(), 'frm_1a17abcc8001lKXKsmCJ1HID8x', 'Red');
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
     * @param array<string, string>               $failing  path suffix => the transport error it raises
     */
    private function transport(array $payloads, array $failing = []): HarnessTransport
    {
        return new class ($payloads, $failing) implements HarnessTransport {
            /**
             * @param array<string, array<string, mixed>> $payloads
             * @param array<string, string>               $failing
             */
            public function __construct(
                private readonly array $payloads,
                private readonly array $failing,
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

                foreach ($this->failing as $suffix => $message) {
                    if (str_ends_with($route, '/' . $suffix)) {
                        throw new HarnessTransportException($message);
                    }
                }

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
     * @param array<string, array<string, mixed>> $pages      message pages keyed by the cursor requested ('' for the first)
     * @param string|null                         $failOnce   a cursor that answers InvalidCursorError the first time it is asked for
     * @param list<string>                        $requested  every cursor asked for, in order
     */
    private function pagedTransport(
        array $pages,
        ?string $failOnce = null,
        array &$requested = [],
        bool $failAlways = false
    ): HarnessTransport {
        return new class ($pages, $failOnce, $requested, $failAlways) implements HarnessTransport {
            /**
             * @param array<string, array<string, mixed>> $pages
             * @param list<string>                        $requested
             */
            public function __construct(
                private readonly array $pages,
                private ?string $failOnce,
                private array &$requested,
                private readonly bool $failAlways,
            ) {
            }

            #[Override]
            public function request(
                string $method,
                string $path,
                ?array $body = null,
                int $timeout = 30
            ): array {
                [$route, $query] = array_pad(explode('?', $path, 2), 2, '');

                if (! str_ends_with($route, '/message')) {
                    return ['data' => []];
                }

                parse_str($query, $params);
                $cursor = (string) ($params['cursor'] ?? '');
                $this->requested[] = $cursor;

                if ($cursor !== '' && $cursor === $this->failOnce) {
                    if (! $this->failAlways) {
                        $this->failOnce = null;
                    }

                    throw new HarnessTransportException(
                        'Harness returned HTTP 400 for GET ' . $route . ': {"_tag":"InvalidCursorError","message":"Cursor cannot be combined with order"}'
                    );
                }

                return $this->pages[$cursor] ?? ['data' => []];
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
     * A verbatim response from opencode 2.2.0, captured through the image the Dockerfile builds.
     *
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/fixtures/opencode-2.2.0/' . $name . '.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed>|null $tokens
     * @param array<string, mixed>|null $error  `Session.StructuredError`; sets `finish: error` as the runtime does
     * @return array<string, mixed>
     */
    private function assistantMessage(
        int $createdAt,
        string $text,
        string $model = 'gpt-4.1',
        ?array $tokens = null,
        ?array $error = null
    ): array {
        $message = [
            'type' => 'assistant',
            'model' => ['id' => $model, 'providerID' => 'oai'],
            'tokens' => $tokens ?? ['input' => 10, 'output' => 5, 'reasoning' => 0, 'cache' => ['read' => 0, 'write' => 0]],
            'cost' => 0,
            'time' => ['created' => $createdAt, 'completed' => $createdAt + 100],
            'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]],
        ];

        if ($error !== null) {
            $message['finish'] = 'error';
            $message['error'] = $error;
        }

        return $message;
    }
}
