<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\DrainOutcomeEnum;
use Kanvas\Connectors\ClaudeAgent\Services\EventDrainService;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * The terminal gate is the highest-risk logic in this connector: every branch here is a different
 * way a turn can end, and collapsing any of them into "done" hands the caller a plausible reply for
 * work that did not happen.
 */
final class EventDrainServiceTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents, types and sessions on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->configureClaudeAgent($this->currentApp, $this->currentCompany);
    }

    /**
     * @param list<array<string, mixed>> $pages
     */
    private function drainOver(array $pages, ?string $cursor = null): object
    {
        $responses = array_map(
            fn (array $page) => $this->claudeAgentJsonResponse(200, $page),
            $pages,
        );

        $client = new Client(
            $this->currentApp,
            $this->currentCompany,
            $this->claudeAgentGuzzleReturning($responses),
        );

        // Zero poll interval keeps the test fast; a tiny deadline bounds the no-terminal cases.
        return new EventDrainService($client, 'sesn_test', $cursor, 50, 0)->drain();
    }

    /**
     * @param array<string, mixed> $stopReason
     */
    private function idle(string $id, array $stopReason): array
    {
        return ['id' => $id, 'type' => 'session.status_idle', 'stop_reason' => $stopReason];
    }

    private function message(string $id, string $text): array
    {
        return [
            'id' => $id,
            'type' => 'agent.message',
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    public function testEndTurnCompletesAndAccumulatesText(): void
    {
        $result = $this->drainOver([[
            'data' => [
                $this->message('sevt_1', 'Reading the file.'),
                $this->message('sevt_2', 'Done — wrote report.xlsx.'),
                $this->idle('sevt_3', ['type' => 'end_turn']),
            ],
            'next_page' => null,
        ]]);

        $this->assertSame(DrainOutcomeEnum::COMPLETED, $result->outcome);
        $this->assertStringContainsString('Reading the file.', $result->text);
        $this->assertStringContainsString('Done — wrote report.xlsx.', $result->text);
        $this->assertSame('sevt_3', $result->cursor);
    }

    /**
     * The single most important branch. A session goes idle transiently whenever it is blocked on
     * the client, so breaking on bare idle would return a half-written reply as the final answer.
     */
    public function testRequiresActionIsNotTreatedAsCompletion(): void
    {
        $result = $this->drainOver([[
            'data' => [
                $this->message('sevt_1', 'Let me look that up.'),
                [
                    'id' => 'sevt_9',
                    'type' => 'agent.custom_tool_use',
                    'name' => 'get_lead',
                    'input' => ['lead_id' => 42],
                ],
                $this->idle('sevt_2', ['type' => 'requires_action', 'event_ids' => ['sevt_9']]),
            ],
            'next_page' => null,
        ]]);

        $this->assertSame(DrainOutcomeEnum::AWAITING_CLIENT, $result->outcome);
        $this->assertFalse($result->outcome->isSuccessful());
        $this->assertSame(
            [['id' => 'sevt_9', 'name' => 'get_lead', 'input' => ['lead_id' => 42]]],
            $result->pendingToolCalls,
        );
    }

    /**
     * The session is blocked on something we never declared — a tool confirmation, or a tool the
     * model invented. The pending list must come back empty so the caller reports that instead of
     * spinning to the deadline and blaming a timeout.
     */
    public function testRequiresActionWithNoMatchingToolCallYieldsNothingToServe(): void
    {
        $result = $this->drainOver([[
            'data' => [
                $this->idle('sevt_2', ['type' => 'requires_action', 'event_ids' => ['sevt_unknown']]),
            ],
            'next_page' => null,
        ]]);

        $this->assertSame(DrainOutcomeEnum::AWAITING_CLIENT, $result->outcome);
        $this->assertSame([], $result->pendingToolCalls);
    }

    public function testBudgetReachedIsItsOwnOutcome(): void
    {
        $result = $this->drainOver([[
            'data' => [$this->idle('sevt_1', ['type' => 'budget_reached'])],
            'next_page' => null,
        ]]);

        $this->assertSame(DrainOutcomeEnum::BUDGET_REACHED, $result->outcome);
        $this->assertFalse($result->outcome->isSuccessful());
    }

    public function testRetriesExhaustedFails(): void
    {
        $result = $this->drainOver([[
            'data' => [$this->idle('sevt_1', ['type' => 'retries_exhausted'])],
            'next_page' => null,
        ]]);

        $this->assertSame(DrainOutcomeEnum::FAILED, $result->outcome);
        $this->assertSame('retries_exhausted', $result->stopReason);
    }

    /**
     * A stop reason this client doesn't know is a new platform state, not a success. Defaulting it
     * to COMPLETED would return whatever text happened to be collected — often nothing — as if the
     * agent had answered.
     */
    public function testAnUnrecognisedStopReasonIsNotSuccess(): void
    {
        $result = $this->drainOver([[
            'data' => [$this->idle('sevt_1', ['type' => 'some_future_state'])],
            'next_page' => null,
        ]]);

        $this->assertSame(DrainOutcomeEnum::FAILED, $result->outcome);
        $this->assertSame('some_future_state', $result->stopReason);
    }

    public function testTerminatedStopsTheDrain(): void
    {
        $result = $this->drainOver([[
            'data' => [
                $this->message('sevt_1', 'Partial answer.'),
                ['id' => 'sevt_2', 'type' => 'session.status_terminated'],
            ],
            'next_page' => null,
        ]]);

        $this->assertSame(DrainOutcomeEnum::TERMINATED, $result->outcome);
        $this->assertSame('Partial answer.', $result->text);
    }

    /**
     * No terminal event ever arrives. The session is still running remotely, so the outcome must say
     * "still going" rather than presenting the partial text as the finished answer.
     */
    public function testNoTerminalEventTimesOut(): void
    {
        $client = new Client(
            $this->currentApp,
            $this->currentCompany,
            $this->claudeAgentGuzzleRepeating(200, [
                'data' => [$this->message('sevt_1', 'Working…')],
                'next_page' => null,
            ]),
        );

        $result = new EventDrainService($client, 'sesn_test', null, 50, 0)->drain();

        $this->assertSame(DrainOutcomeEnum::TIMED_OUT, $result->outcome);
        // The partial text survives so the caller can show it alongside a "still working" note.
        $this->assertSame('Working…', $result->text);
    }

    /**
     * A zero deadline must consume exactly one pass and return, rather than blocking or looping.
     *
     * This is the contract the async path depends on: a queued poller ticks the drain, gets either a
     * terminal outcome or TIMED_OUT ("still running, re-dispatch"), and persists the cursor — the
     * same service, driven one pass at a time instead of held open.
     */
    public function testAZeroDeadlineDrainsExactlyOnePass(): void
    {
        $client = new Client(
            $this->currentApp,
            $this->currentCompany,
            // Exactly one queued response: a second request would throw "Mock queue is empty".
            $this->claudeAgentGuzzleReturning([
                $this->claudeAgentJsonResponse(200, [
                    'data' => [$this->message('sevt_1', 'Still working…')],
                    'next_page' => null,
                ]),
            ]),
        );

        $result = new EventDrainService($client, 'sesn_test', null, 0, 0)->drain();

        $this->assertSame(DrainOutcomeEnum::TIMED_OUT, $result->outcome);
        $this->assertSame('Still working…', $result->text);
        $this->assertSame('sevt_1', $result->cursor);
    }

    /**
     * Without the cursor every turn would replay the entire conversation back into the reply.
     */
    public function testCursorSkipsEventsAlreadyReturned(): void
    {
        $result = $this->drainOver([[
            'data' => [
                $this->message('sevt_1', 'Old turn, already delivered.'),
                $this->message('sevt_2', 'Also old.'),
                $this->message('sevt_3', 'This is the new answer.'),
                $this->idle('sevt_4', ['type' => 'end_turn']),
            ],
            'next_page' => null,
        ]], cursor: 'sevt_2');

        $this->assertSame('This is the new answer.', $result->text);
        $this->assertStringNotContainsString('Old turn', $result->text);
        $this->assertSame('sevt_4', $result->cursor);
    }

    public function testPaginationIsFollowedWithinASinglePass(): void
    {
        $result = $this->drainOver([
            ['data' => [$this->message('sevt_1', 'First page.')], 'next_page' => 'cursor_2'],
            [
                'data' => [
                    $this->message('sevt_2', 'Second page.'),
                    $this->idle('sevt_3', ['type' => 'end_turn']),
                ],
                'next_page' => null,
            ],
        ]);

        $this->assertSame(DrainOutcomeEnum::COMPLETED, $result->outcome);
        $this->assertStringContainsString('First page.', $result->text);
        $this->assertStringContainsString('Second page.', $result->text);
    }

    public function testNonTextContentBlocksAreIgnored(): void
    {
        $result = $this->drainOver([[
            'data' => [
                [
                    'id' => 'sevt_1',
                    'type' => 'agent.message',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => ''],
                        ['type' => 'text', 'text' => 'Visible answer.'],
                    ],
                ],
                $this->idle('sevt_2', ['type' => 'end_turn']),
            ],
            'next_page' => null,
        ]]);

        $this->assertSame('Visible answer.', $result->text);
    }
}
