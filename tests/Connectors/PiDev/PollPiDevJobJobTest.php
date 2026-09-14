<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PiDev\Client;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\PiDev\Jobs\PollPiDevJobJob;
use Kanvas\Connectors\PiDev\Jobs\RetryPiDevJobJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Override;
use Tests\Connectors\Traits\HasPiDevConfiguration;
use Tests\TestCase;

final class PollPiDevJobJobTest extends TestCase
{
    use HasPiDevConfiguration;

    public function testTerminalTaskIsNotRescheduledAndMakesNoHttpCall(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::DONE);

        // A terminal task must return before building the HTTP client (no config set → would throw if it tried).
        new PollPiDevJobJob($app, $task->getId())->handle();

        Queue::assertNotPushed(PollPiDevJobJob::class);
    }

    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);
    }

    /**
     * @param array<string, mixed> $jobPayload What GET /agents/work/{id} returns for this poll.
     */
    private function pollWithPiDevReturning(Task $task, array $jobPayload): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $job = new PollPiDevJobJobWithFakeClient($app, $task->getId());

        // Two responses: the status fetch, then the best-effort SSE drain postProgressComments does.
        $job->fakeClient = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(200, $jobPayload),
            $this->piDevJsonResponse(200, []),
        ]);

        $job->handle();
    }

    public function testProviderErrorSchedulesARetryInsteadOfBlockingTheTask(): void
    {
        Queue::fake();

        $task = $this->makeCodingTaskForAgent($this->makeAgent(), TaskStatusEnum::IN_PROGRESS);

        $this->pollWithPiDevReturning($task, [
            'jobId' => 'job-1',
            'status' => 'failed',
            'error' => 'You have reached your specified API usage limits.',
            'errorCode' => 'provider_error',
        ]);

        $fresh = $task->fresh();
        $this->assertNotSame(
            TaskStatusEnum::BLOCKED->value,
            $fresh->status,
            'A provider error must not land the task in a terminal state the poller can never leave.'
        );
        $this->assertSame(1, (int) $fresh->get(TaskCustomFieldEnum::PIDEV_AUTO_RETRY_COUNT->value));

        Queue::assertPushed(
            RetryPiDevJobJob::class,
            fn (RetryPiDevJobJob $job) => $job->taskId === $task->getId()
        );
    }

    public function testARealFailureStillBlocksAndIsNeverRetried(): void
    {
        Queue::fake();

        $task = $this->makeCodingTaskForAgent($this->makeAgent(), TaskStatusEnum::IN_PROGRESS);

        $this->pollWithPiDevReturning($task, [
            'jobId' => 'job-1',
            'status' => 'failed',
            'error' => 'git clone failed (exit 128): remote: Repository not found.',
        ]);

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->fresh()->status);
        Queue::assertNotPushed(RetryPiDevJobJob::class);
    }

    public function testTheAutoRetryBudgetIsFiniteAndThenTheJobIsAllowedToFail(): void
    {
        Queue::fake();

        $task = $this->makeCodingTaskForAgent($this->makeAgent(), TaskStatusEnum::IN_PROGRESS);
        $task->set(TaskCustomFieldEnum::PIDEV_AUTO_RETRY_COUNT->value, 3);

        $this->pollWithPiDevReturning($task, [
            'jobId' => 'job-1',
            'status' => 'failed',
            'error' => 'You have reached your specified API usage limits.',
            'errorCode' => 'provider_error',
        ]);

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->fresh()->status);
        Queue::assertNotPushed(RetryPiDevJobJob::class);
    }
}

/**
 * Drives the real poller against canned pi.dev responses via the job's makeClient() seam.
 */
final class PollPiDevJobJobWithFakeClient extends PollPiDevJobJob
{
    public ?Client $fakeClient = null;

    #[Override]
    protected function makeClient(Task $task): Client
    {
        return $this->fakeClient;
    }
}
