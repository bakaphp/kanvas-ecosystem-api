<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\FinalizeHarnessSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\LaunchTaskSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\ResumeParentTaskJob;
use Kanvas\NervousSystem\Plan\Models\Task;
use PHPUnit\Framework\Attributes\Group;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A run that produced nothing gets one more try before anyone is told it failed. Serial because the
 * retry dispatch reads app settings that live in Redis, shared across paratest processes.
 */
#[Group('serial')]
class CodingRetryTest extends TestCase
{
    use CreatesTaskSessions;
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence', 'social'];

    private mixed $originalEndpoint = null;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Notification::fake();

        // Launch mode: the retry is a queued launch, not a synchronous attach to a server.
        $app = app(Apps::class);
        $this->originalEndpoint = $app->get(ConfigurationEnum::STATIC_ENDPOINT->value);
        $app->set(ConfigurationEnum::STATIC_ENDPOINT->value, null);
    }

    protected function tearDown(): void
    {
        app(Apps::class)->set(ConfigurationEnum::STATIC_ENDPOINT->value, $this->originalEndpoint);

        parent::tearDown();
    }

    public function test_an_empty_first_run_is_retried_once_on_the_same_model(): void
    {
        [$parent, $child, $session] = $this->delegatedCodingJob(branch: null, attributes: ['model' => 'gpt-test']);

        new FinalizeHarnessSessionAction($session, $this->completedTick(), diff: new HarnessDiff())->execute();

        $this->assertSame(HarnessStatusEnum::FAILED->value, $session->refresh()->status);
        $retry = $this->retryOf($session);
        $this->assertSame('gpt-test', $retry->model);
        $this->assertSame(HarnessStatusEnum::STARTING->value, $retry->status);
        $this->assertSame($child->getId(), $retry->task_id);
        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $child->refresh()->status);
        $this->assertSame(TaskStatusEnum::WAITING->value, $parent->refresh()->status);
        Bus::assertDispatched(LaunchTaskSessionJob::class, fn (LaunchTaskSessionJob $job): bool => $job->sessionId === $retry->getId());
        Bus::assertNotDispatched(ResumeParentTaskJob::class);
        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Task::class,
                'source_entity_id' => $child->getId(),
                'event_type' => 'coding.session.retried',
            ],
            'intelligence',
        );
    }

    public function test_a_retry_that_is_empty_again_blocks_the_task_and_resumes_the_parent(): void
    {
        [$parent, $child, $first] = $this->delegatedCodingJob(HarnessStatusEnum::FAILED, branch: null);
        $retry = $this->createTaskSession(HarnessStatusEnum::RUNNING, [
            'task_id' => $child->getId(),
            'plan_id' => $child->plan_id,
            'companies_id' => $child->companies_id,
            'agent_id' => $first->agent_id,
            'retry_of_session_id' => $first->getId(),
        ])->refresh();

        new FinalizeHarnessSessionAction($retry, $this->completedTick(), diff: new HarnessDiff())->execute();

        $this->assertSame(2, AgentTaskSession::query()->forTask($child->getId())->count());
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $child->refresh()->status);
        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $parent->refresh()->status);
        Bus::assertDispatchedTimes(ResumeParentTaskJob::class, 1);
    }

    public function test_a_runtime_that_never_spoke_is_retried_but_one_that_worked_is_not(): void
    {
        [, $silentTask, $silent] = $this->delegatedCodingJob(branch: null);
        [, $busyTask, $busy] = $this->delegatedCodingJob(branch: null, attributes: ['input_tokens' => 900, 'output_tokens' => 120]);

        foreach ([$silent, $busy] as $session) {
            $session->status = HarnessStatusEnum::FAILED->value;
            $session->saveOrFail();
            new FinalizeHarnessSessionAction(
                $session,
                null,
                'The coding session became unreachable.',
                silence: true,
            )->execute();
        }

        $this->assertSame(2, AgentTaskSession::query()->forTask($silentTask->getId())->count());
        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $silentTask->refresh()->status);
        $this->assertSame(1, AgentTaskSession::query()->forTask($busyTask->getId())->count());
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $busyTask->refresh()->status);
    }

    public function test_a_run_a_person_stopped_is_not_retried(): void
    {
        [, $child, $session] = $this->delegatedCodingJob(HarnessStatusEnum::CANCELLED, branch: null);

        new FinalizeHarnessSessionAction($session, failureReason: 'Cancelled: wrong repository')->execute();

        $this->assertSame(1, AgentTaskSession::query()->forTask($child->getId())->count());
        $this->assertSame(TaskStatusEnum::SKIPPED->value, $child->refresh()->status);
    }

    public function test_a_tenant_fallback_model_is_used_for_the_retry(): void
    {
        [, $child, $session] = $this->delegatedCodingJob(branch: null, attributes: ['model' => 'gpt-test']);
        $company = $child->company;
        $original = $company->get(ConfigurationEnum::FALLBACK_MODEL->value);
        $company->set(ConfigurationEnum::FALLBACK_MODEL->value, 'gpt-strong');

        try {
            new FinalizeHarnessSessionAction($session, $this->completedTick(), diff: new HarnessDiff())->execute();

            $this->assertSame('gpt-strong', $this->retryOf($session)->model);
        } finally {
            $company->set(ConfigurationEnum::FALLBACK_MODEL->value, $original);
        }
    }

    private function retryOf(AgentTaskSession $failed): AgentTaskSession
    {
        /** @var AgentTaskSession|null $retry */
        $retry = AgentTaskSession::query()->where('retry_of_session_id', $failed->getId())->first();
        $this->assertNotNull($retry, 'No retry session was created.');

        return $retry;
    }
}
