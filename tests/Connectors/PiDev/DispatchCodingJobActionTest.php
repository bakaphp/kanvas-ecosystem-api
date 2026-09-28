<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PiDev\Actions\ConfigureAgentAction;
use Kanvas\Connectors\PiDev\Actions\DispatchCodingJobAction;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\PiDev\Jobs\PollPiDevJobJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Tests\Connectors\Traits\HasPiDevConfiguration;
use Tests\TestCase;

final class DispatchCodingJobActionTest extends TestCase
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
            ->create(['agent_type_id' => $agentType->getId(), 'is_active' => 1]);

        new ConfigureAgentAction(
            agent: $agent,
            githubToken: 'ghp_test_token',
            allowedRepos: [
                ['slug' => 'widgets', 'url' => 'https://github.com/acme/widgets.git', 'base_branch' => 'main'],
            ],
        )->execute();

        return $agent;
    }

    public function testDispatchCreatesPlanTaskWithLinkageAndSchedulesPoller(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeConfiguredAgent();

        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(202, [
                'jobId' => 'job-1',
                'agentId' => (string) $agent->uuid,
                'status' => 'queued',
                'repoName' => 'widgets',
            ]),
        ]);

        $task = new DispatchCodingJobAction($agent, 'widgets', 'Add a changelog', $client)->execute();

        $this->assertSame($agent->getId(), $task->agent_id);
        $this->assertSame('coding_job', $task->plan->plan_type);
        $this->assertSame($agent->getId(), $task->plan->agent_id);
        // Plan has an owner user so PlanObserver creates the Activities channel we post the result to.
        $this->assertNotNull($task->plan->users_id);
        $this->assertSame('job-1', $task->get(TaskCustomFieldEnum::PIDEV_JOB_ID->value));
        $this->assertSame('queued', $task->get(TaskCustomFieldEnum::PIDEV_STATUS->value));
        $this->assertSame('widgets', $task->get(TaskCustomFieldEnum::PIDEV_REPO_SLUG->value));

        Queue::assertPushed(PollPiDevJobJob::class);
        // Regression: the coding-job plan is created fromSync so it must NOT wake the agent —
        // otherwise the agent re-dispatches → new plan → wake → infinite dispatch loop.
        Queue::assertNotPushed(WakeAgentForPlanJob::class);
    }

    public function testRequesterOwnsThePlanForNotification(): void
    {
        // Fake the queue so the poller isn't run inline (sync queue in CI would hit the network).
        Queue::fake();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $requester = auth()->user();
        $agent = $this->makeConfiguredAgent();

        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(202, ['jobId' => 'job-1', 'status' => 'queued', 'repoName' => 'widgets']),
        ]);

        $task = new DispatchCodingJobAction($agent, 'widgets', 'Add a changelog', $client, requestedBy: $requester)->execute();

        // The human who asked owns the plan — that's who the poller notifies on completion.
        $this->assertSame($requester->getId(), $task->plan->users_id);
    }

    public function testDispatchRejectsRepoOutsideAllowList(): void
    {
        $agent = $this->makeConfiguredAgent();

        $this->expectException(ValidationException::class);

        new DispatchCodingJobAction($agent, 'not-allowed', 'Do something')->execute();
    }

    public function testDispatchRejectsUnconfiguredAgent(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        $this->expectException(ValidationException::class);

        new DispatchCodingJobAction($agent, 'widgets', 'Do something')->execute();
    }
}
