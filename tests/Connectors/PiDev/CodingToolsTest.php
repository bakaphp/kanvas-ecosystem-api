<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PiDev\Actions\ConfigureAgentAction;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Tools\Coding\CancelCodingJobTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Coding\CheckCodingJobStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Coding\CheckCodingSetupTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Coding\DispatchCodingTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Coding\ListMyCodingJobsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Coding\RetryCodingJobTool;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\Connectors\Traits\HasPiDevConfiguration;
use Tests\TestCase;

final class CodingToolsTest extends TestCase
{
    use HasPiDevConfiguration;

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

    private function configure(Agent $agent): void
    {
        new ConfigureAgentAction(
            agent: $agent,
            githubToken: 'ghp_test_token',
            allowedRepos: [['slug' => 'widgets', 'url' => 'https://github.com/acme/widgets.git']],
        )->execute();
    }

    public function testDispatchToolRejectsEmptyInput(): void
    {
        $result = new DispatchCodingTaskTool($this->makeAgent())('', '');

        $this->assertSame('error', $result['status']);
    }

    public function testDispatchToolRejectsRepoOutsideAllowList(): void
    {
        $agent = $this->makeAgent();
        $this->configure($agent);

        $result = new DispatchCodingTaskTool($agent)('not-allowed', 'Do something');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('allow-list', $result['message']);
    }

    public function testDispatchToolRejectsUnconfiguredAgent(): void
    {
        $result = new DispatchCodingTaskTool($this->makeAgent())('widgets', 'Do something');

        $this->assertSame('error', $result['status']);
    }

    public function testCheckToolReturnsStatusAndPullRequest(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent();
        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::DONE);
        $task->set(TaskCustomFieldEnum::PIDEV_STATUS->value, 'completed');
        $task->set(TaskCustomFieldEnum::PIDEV_PULL_REQUEST_URL->value, 'https://github.com/acme/widgets/pull/42');

        $result = new CheckCodingJobStatusTool($app, $company, $agent)($task->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame(TaskStatusEnum::DONE->value, $result['task_status']);
        $this->assertSame('completed', $result['pidev_status']);
        $this->assertSame('https://github.com/acme/widgets/pull/42', $result['pull_request_url']);
    }

    public function testCheckToolRejectsUnknownJob(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $result = new CheckCodingJobStatusTool($app, $company, $this->makeAgent())(999999999);

        $this->assertSame('error', $result['status']);
    }

    public function testCheckToolCannotSeeAnotherAgentsJob(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $otherAgent = $this->makeAgent();
        $task = $this->makeCodingTaskForAgent($otherAgent, TaskStatusEnum::IN_PROGRESS);

        // A different agent must not resolve the first agent's job.
        $result = new CheckCodingJobStatusTool($app, $company, $this->makeAgent())($task->getId());

        $this->assertSame('error', $result['status']);
    }

    public function testListToolReturnsOnlyThisAgentsJobs(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent();
        $otherAgent = $this->makeAgent();

        $this->makeCodingTaskForAgent($agent, TaskStatusEnum::IN_PROGRESS);
        $this->makeCodingTaskForAgent($agent, TaskStatusEnum::DONE);
        $this->makeCodingTaskForAgent($otherAgent, TaskStatusEnum::IN_PROGRESS);

        $result = new ListMyCodingJobsTool($app, $company, $agent)();

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['count']);
        foreach ($result['jobs'] as $job) {
            $this->assertArrayHasKey('job_id', $job);
            $this->assertArrayHasKey('pull_request_url', $job);
        }
    }

    public function testCheckSetupReportsReadyWhenConfigured(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent();
        $this->configure($agent);

        // Mocked getJob(sentinel) → 404 = reachable + authorized (token accepted, job just missing).
        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(404, ['error' => 'job not found']),
        ]);

        $result = new CheckCodingSetupTool($agent, $client)();

        $this->assertTrue($result['ready']);
        $this->assertSame(['widgets'], $result['allowed_repos']);
        $this->assertSame([], $result['issues']);
    }

    public function testCheckSetupReportsNotReadyWhenAgentUnconfigured(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent();

        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(404, ['error' => 'job not found']),
        ]);

        $result = new CheckCodingSetupTool($agent, $client)();

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['checks']['github_token']);
        $this->assertFalse($result['checks']['allowed_repos']);
        $this->assertTrue($result['checks']['pidev_authorized']);
        $this->assertNotEmpty($result['issues']);
    }

    public function testCheckSetupReportsUnauthorizedOn401(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent();
        $this->configure($agent);

        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(401, ['error' => 'unauthorized']),
        ]);

        $result = new CheckCodingSetupTool($agent, $client)();

        $this->assertFalse($result['ready']);
        $this->assertTrue($result['checks']['pidev_reachable']);
        $this->assertFalse($result['checks']['pidev_authorized']);
    }

    public function testCancelToolIsSuccessOnTerminalJob(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent();
        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::DONE);

        // Terminal task → CancelCodingJobAction returns before any HTTP call.
        $result = new CancelCodingJobTool($app, $company, $agent)($task->getId());

        $this->assertSame('success', $result['status']);
    }

    public function testRetryToolRefusesAJobBelongingToAnotherAgent(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $owner = $this->makeAgent();
        $this->configure($owner);
        $task = $this->makeCodingTaskForAgent($owner, TaskStatusEnum::BLOCKED);

        $result = new RetryCodingJobTool($app, $company, $this->makeAgent())($task->getId());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not found for this agent', $result['message']);
    }

    public function testRetryToolReportsAMissingJobAsAnErrorRatherThanThrowing(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $result = new RetryCodingJobTool($app, $company, $this->makeAgent())(999999999);

        $this->assertSame('error', $result['status']);
    }

    public function testRetryToolSurfacesWhyATaskCannotBeRetried(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agent = $this->makeAgent();
        $this->configure($agent);

        // Never dispatched to pi.dev, so there is no job to re-send.
        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::BLOCKED, pidevJobId: null);

        $result = new RetryCodingJobTool($app, $company, $agent)($task->getId());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('never reached pi.dev', $result['message']);
    }
}
