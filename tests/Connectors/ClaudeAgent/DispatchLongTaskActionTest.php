<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\DispatchLongTaskAction;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Jobs\PollClaudeSessionJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * Dispatch must return as soon as the session is open — never when the work is done. A queue worker
 * caps out around an hour and a hosted session can run longer, so anything that blocks here is a bug
 * that only shows up in production.
 */
final class DispatchLongTaskActionTest extends TestCase
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

    private function makeAgent(): Agent
    {
        return $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
    }

    /**
     * @return list<\GuzzleHttp\Psr7\Response>
     */
    private function happyPath(): array
    {
        return [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
            $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
        ];
    }

    public function testItCreatesAPlanAndTaskAndOpensTheSession(): void
    {
        $agent = $this->makeAgent();

        $task = new DispatchLongTaskAction(
            agent: $agent,
            brief: 'Audit the product catalog for pricing anomalies and write a report.',
            client: $this->claudeAgentClientReturning(
                $this->currentApp,
                $this->currentCompany,
                $this->happyPath(),
            ),
        )->execute();

        $this->assertSame($agent->getId(), $task->agent_id);
        $this->assertSame('sesn_01', $task->get(TaskCustomFieldEnum::CLAUDE_SESSION_ID->value));
        $this->assertSame(DispatchLongTaskAction::PLAN_TYPE, $task->plan->plan_type);
        $this->assertStringContainsString('pricing anomalies', (string) $task->description);
    }

    /**
     * The single most important assertion in this file: dispatch is not delivery. A task that lands
     * `done` here would tell the PM the work finished when it has only started.
     */
    public function testTheTaskStartsInProgressNeverDone(): void
    {
        $task = new DispatchLongTaskAction(
            agent: $this->makeAgent(),
            brief: 'Long running job.',
            client: $this->claudeAgentClientReturning(
                $this->currentApp,
                $this->currentCompany,
                $this->happyPath(),
            ),
        )->execute();

        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $task->status);
        $this->assertNotSame(TaskStatusEnum::DONE->value, $task->status);
    }

    public function testItHandsOffToThePoller(): void
    {
        $task = new DispatchLongTaskAction(
            agent: $this->makeAgent(),
            brief: 'Long running job.',
            client: $this->claudeAgentClientReturning(
                $this->currentApp,
                $this->currentCompany,
                $this->happyPath(),
            ),
        )->execute();

        Queue::assertPushed(
            PollClaudeSessionJob::class,
            fn (PollClaudeSessionJob $job): bool => $job->taskId === $task->getId(),
        );
    }

    /**
     * A rubric turns the run into a graded loop the platform iterates on its own. Sending a plain
     * message alongside it would have the agent work the brief twice.
     */
    public function testARubricSeedsAnOutcomeInsteadOfAMessage(): void
    {
        $captured = null;

        $client = $this->claudeAgentClientCapturing(
            $this->currentApp,
            $this->currentCompany,
            $this->happyPath(),
            $captured,
        );

        new DispatchLongTaskAction(
            agent: $this->makeAgent(),
            brief: 'Build the reconciliation workbook.',
            rubric: 'Every account reconciled; trial balance nets to zero.',
            maxIterations: 5,
            client: $client,
        )->execute();

        $events = $captured['/v1/sessions']['initial_events'] ?? [];

        $this->assertSame('user.define_outcome', $events[0]['type']);
        $this->assertSame('Build the reconciliation workbook.', $events[0]['description']);
        $this->assertSame(5, $events[0]['max_iterations']);
        $this->assertStringContainsString('nets to zero', $events[0]['rubric']['content']);
    }

    public function testWithoutARubricItSeedsAPlainUserMessage(): void
    {
        $captured = null;

        $client = $this->claudeAgentClientCapturing(
            $this->currentApp,
            $this->currentCompany,
            $this->happyPath(),
            $captured,
        );

        new DispatchLongTaskAction(
            agent: $this->makeAgent(),
            brief: 'Just do the thing.',
            client: $client,
        )->execute();

        $events = $captured['/v1/sessions']['initial_events'] ?? [];

        $this->assertSame('user.message', $events[0]['type']);
        $this->assertSame('Just do the thing.', $events[0]['content'][0]['text']);
    }

    /**
     * An unknown slug must fail before the plan exists — otherwise an orphan plan points at a
     * session that was never opened.
     */
    public function testAnUnknownRepoSlugFailsBeforeAnythingIsCreated(): void
    {
        $agent = $this->makeAgent();
        $agent->set(CustomFieldEnum::CLAUDE_ALLOWED_REPOS->value, [
            ['slug' => 'api', 'url' => 'https://github.com/acme/api'],
        ]);
        $agent->set(CustomFieldEnum::CLAUDE_GITHUB_TOKEN->value, 'github_pat_test');

        $plansBefore = Plan::where('agent_id', $agent->getId())->count();

        try {
            new DispatchLongTaskAction(
                agent: $agent,
                brief: 'Change something.',
                repoSlugs: ['not-mine'],
                client: $this->claudeAgentClientReturning(
                    $this->currentApp,
                    $this->currentCompany,
                    $this->happyPath(),
                ),
            )->execute();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not in this agent', $e->getMessage());
        }

        $this->assertSame($plansBefore, Plan::where('agent_id', $agent->getId())->count());
        Queue::assertNotPushed(PollClaudeSessionJob::class);
    }

    public function testAnEmptyBriefIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new DispatchLongTaskAction(
            agent: $this->makeAgent(),
            brief: '   ',
            client: $this->claudeAgentClientReturning(
                $this->currentApp,
                $this->currentCompany,
                $this->happyPath(),
            ),
        )->execute();
    }
}
