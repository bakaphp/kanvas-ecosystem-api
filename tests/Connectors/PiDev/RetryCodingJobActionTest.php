<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PiDev\Actions\ConfigureAgentAction;
use Kanvas\Connectors\PiDev\Actions\RetryCodingJobAction;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\PiDev\Jobs\PollPiDevJobJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Tests\Connectors\Traits\HasPiDevConfiguration;
use Tests\TestCase;

final class RetryCodingJobActionTest extends TestCase
{
    use HasPiDevConfiguration;

    private function makeConfiguredAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        new ConfigureAgentAction(
            agent: $agent,
            githubToken: 'ghp_test_token',
            allowedRepos: [['slug' => 'widgets', 'url' => 'https://github.com/acme/widgets.git']],
        )->execute();

        return $agent;
    }

    public function testReusesTheSamePlanAndTaskInsteadOfCreatingNewOnes(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeConfiguredAgent();

        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::BLOCKED, 'job-dead');
        $task->blocked_reason = 'You have reached your specified API usage limits.';
        $task->saveQuietly();

        $plan = $task->plan;
        $plan->status = PlanStatusEnum::FAILED->value;
        $plan->saveQuietly();

        $taskCountBefore = Task::query()->fromApp($app)->fromCompany($company)->count();

        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(202, [
                'jobId' => 'job-fresh',
                'agentId' => $agent->uuid,
                'status' => 'queued',
            ]),
        ]);

        new RetryCodingJobAction($task, $client)->execute();

        $this->assertSame(
            $taskCountBefore,
            Task::query()->fromApp($app)->fromCompany($company)->count(),
            'Retry must not mint a second task.'
        );

        $fresh = $task->fresh();
        $this->assertSame('job-fresh', $fresh->get(TaskCustomFieldEnum::PIDEV_JOB_ID->value));
        $this->assertSame(TaskStatusEnum::PENDING->value, $fresh->status);
        $this->assertNull($fresh->blocked_reason);
        $this->assertSame(PlanStatusEnum::ACTIVE->value, $fresh->plan->status);
    }

    public function testClearsStalePullRequestAndEventCursorFromTheDeadRun(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeConfiguredAgent();

        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::BLOCKED, 'job-dead');
        $task->set(TaskCustomFieldEnum::PIDEV_PULL_REQUEST_URL->value, 'https://github.com/acme/widgets/pull/1');
        $task->set(TaskCustomFieldEnum::PIDEV_EVENTS_CURSOR->value, 42);

        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(202, ['jobId' => 'job-fresh', 'status' => 'queued']),
        ]);

        new RetryCodingJobAction($task, $client)->execute();

        $fresh = $task->fresh();
        $this->assertNull($fresh->get(TaskCustomFieldEnum::PIDEV_PULL_REQUEST_URL->value));
        $this->assertSame(0, (int) $fresh->get(TaskCustomFieldEnum::PIDEV_EVENTS_CURSOR->value));
    }

    public function testQueuesThePollerSoTheNewJobIsActuallyTracked(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeConfiguredAgent();
        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::BLOCKED, 'job-dead');

        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(202, ['jobId' => 'job-fresh', 'status' => 'queued']),
        ]);

        new RetryCodingJobAction($task, $client)->execute();

        Queue::assertPushed(
            PollPiDevJobJob::class,
            fn (PollPiDevJobJob $job) => $job->taskId === $task->getId()
        );
    }

    public function testRejectsATaskThatNeverReachedPiDev(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeConfiguredAgent();

        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::BLOCKED, pidevJobId: null);

        $this->expectException(ValidationException::class);

        new RetryCodingJobAction(
            $task,
            $this->piDevClientReturning($app, $company, [])
        )->execute();
    }

    public function testRejectsAnAgentThatLostItsGithubToken(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::BLOCKED, 'job-dead');

        $this->expectException(ValidationException::class);

        new RetryCodingJobAction(
            $task,
            $this->piDevClientReturning($app, $company, [])
        )->execute();
    }
}
