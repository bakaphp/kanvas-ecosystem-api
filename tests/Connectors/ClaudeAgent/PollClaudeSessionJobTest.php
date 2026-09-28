<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\AdvanceLongTaskAction;
use Kanvas\Connectors\ClaudeAgent\Actions\DispatchLongTaskAction;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Connectors\ClaudeAgent\Enums\DrainOutcomeEnum;
use Kanvas\Connectors\ClaudeAgent\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Jobs\PollClaudeSessionJob;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * The poller is the only thing that may write a terminal status. Everything here is about that
 * boundary: text the agent produced is narration, and a session that is merely idle is not done.
 */
final class PollClaudeSessionJobTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents, plans and tasks on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->configureClaudeAgent($this->currentApp, $this->currentCompany);
        $this->currentCompany->set(ConfigurationEnum::ENVIRONMENT_ID->value, 'env_cached');
        Queue::fake();
    }

    private function dispatchTask(): Task
    {
        return new DispatchLongTaskAction(
            agent: $this->makeClaudeAgent($this->currentApp, $this->currentCompany),
            brief: 'Do the long thing.',
            client: $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
                $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
                $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
            ]),
        )->execute();
    }

    /**
     * Drives the action the job delegates to, with an injected Client. The job itself is serialized
     * and builds its own, which is exactly why the logic does not live there.
     *
     * @param list<\GuzzleHttp\Psr7\Response> $responses
     */
    private function poll(Task $task, array $responses): Task
    {
        new AdvanceLongTaskAction(
            $task,
            $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, $responses),
        )->execute();

        return Task::getById($task->getId(), $this->currentApp);
    }

    /**
     * @param list<\GuzzleHttp\Psr7\Response> $responses
     */
    private function pollReschedules(Task $task, array $responses): bool
    {
        return new AdvanceLongTaskAction(
            $task,
            $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, $responses),
        )->execute();
    }

    private function idleEvents(string $stopReason, string $text = 'All done.'): array
    {
        return [
            'data' => [
                [
                    'id' => 'sevt_1',
                    'type' => 'agent.message',
                    'content' => [['type' => 'text', 'text' => $text]],
                ],
                ['id' => 'sevt_2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => $stopReason]],
            ],
            'next_page' => null,
        ];
    }

    public function testEndTurnMarksTheTaskDone(): void
    {
        $task = $this->poll($this->dispatchTask(), [
            $this->claudeAgentJsonResponse(200, $this->idleEvents('end_turn')),
        ]);

        $this->assertSame(TaskStatusEnum::DONE->value, $task->status);
        $this->assertSame(
            DrainOutcomeEnum::COMPLETED->value,
            $task->get(TaskCustomFieldEnum::CLAUDE_STATUS->value),
        );
    }

    /**
     * Still running means still running. Writing a terminal status on a timeout would report a
     * partial as a finished answer, which is the failure this whole path is designed to avoid.
     */
    public function testAnUnfinishedSessionStaysInProgressAndReschedules(): void
    {
        $pending = $this->dispatchTask();

        $this->assertTrue($this->pollReschedules($pending, [
            $this->claudeAgentJsonResponse(200, [
                'data' => [[
                    'id' => 'sevt_1',
                    'type' => 'agent.message',
                    'content' => [['type' => 'text', 'text' => 'Working…']],
                ]],
                'next_page' => null,
            ]),
        ]));

        $task = $this->poll($this->dispatchTask(), [
            $this->claudeAgentJsonResponse(200, [
                'data' => [[
                    'id' => 'sevt_1',
                    'type' => 'agent.message',
                    'content' => [['type' => 'text', 'text' => 'Working…']],
                ]],
                'next_page' => null,
            ]),
        ]);

        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $task->status);
    }

    public function testBudgetReachedBlocksWithAnActionableReason(): void
    {
        $task = $this->poll($this->dispatchTask(), [
            $this->claudeAgentJsonResponse(200, $this->idleEvents('budget_reached', 'Partial work.')),
        ]);

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('spend limit', (string) $task->blocked_reason);
    }

    public function testRetriesExhaustedBlocksAndKeepsTheRawStopReason(): void
    {
        $task = $this->poll($this->dispatchTask(), [
            $this->claudeAgentJsonResponse(200, $this->idleEvents('retries_exhausted', '')),
        ]);

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('retries_exhausted', (string) $task->blocked_reason);
    }

    /**
     * A session the platform has forgotten is gone for good — retrying just burns ticks against a
     * 404 until the runtime ceiling. pi.dev taught us the same lesson.
     */
    public function testAMissingSessionBlocksRatherThanPollingForever(): void
    {
        $task = $this->poll($this->dispatchTask(), [
            $this->claudeAgentJsonResponse(404, [
                'type' => 'error',
                'error' => ['type' => 'not_found_error', 'message' => 'session not found'],
            ]),
        ]);

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('no longer exists', (string) $task->blocked_reason);
    }

    public function testTheCursorAdvancesSoTheNextTickDoesNotReplayHistory(): void
    {
        $task = $this->poll($this->dispatchTask(), [
            $this->claudeAgentJsonResponse(200, [
                'data' => [[
                    'id' => 'sevt_7',
                    'type' => 'agent.message',
                    'content' => [['type' => 'text', 'text' => 'Progress.']],
                ]],
                'next_page' => null,
            ]),
        ]);

        $this->assertSame('sevt_7', $task->get(TaskCustomFieldEnum::CLAUDE_EVENT_CURSOR->value));
    }

    /**
     * A task someone already closed must not be resurrected by an in-flight tick.
     */
    public function testAnAlreadyTerminalTaskIsLeftAlone(): void
    {
        $task = $this->dispatchTask();
        $task->status = TaskStatusEnum::DONE->value;
        $task->saveQuietly();

        // Empty mock queue: any HTTP call at all would throw.
        $polled = $this->poll($task, []);

        $this->assertSame(TaskStatusEnum::DONE->value, $polled->status);
    }

    public function testATaskPastTheRuntimeCeilingIsBlocked(): void
    {
        $task = $this->dispatchTask();
        $task->set(TaskCustomFieldEnum::CLAUDE_STARTED_AT->value, now()->subHours(13)->toIso8601String());

        $polled = $this->poll($task, []);

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $polled->status);
        $this->assertStringContainsString('maximum runtime', (string) $polled->blocked_reason);
    }

    public function testAnAgentlessTaskIsBlockedRatherThanCrashing(): void
    {
        $task = $this->dispatchTask();
        $task->agent_id = null;
        $task->saveQuietly();

        $polled = $this->poll($task, []);

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $polled->status);
    }

    public function testTheJobRunsOnTheAgentRuntimeQueue(): void
    {
        $this->assertSame(
            'agent-runtime',
            new PollClaudeSessionJob($this->currentApp, 1)->queue,
        );
    }

    /**
     * `overwriteAppService()` must be the first thing handle() does: the worker is long-lived and
     * Bouncer's scope is process-global, so a leaked scope breaks every Role lookup the tool bridge
     * makes — surfacing far from the cause.
     */
    public function testHandleRebindsTheAppBeforeDoingAnyWork(): void
    {
        $source = file_get_contents(
            base_path('src/Domains/Connectors/ClaudeAgent/Jobs/PollClaudeSessionJob.php'),
        );

        $body = substr((string) $source, (int) strpos((string) $source, 'public function handle(): void'));

        $this->assertMatchesRegularExpression(
            '/public function handle\(\): void\s*\{\s*(\/\/[^\n]*\n\s*)*\$this->overwriteAppService/',
            $body,
        );
    }
}
