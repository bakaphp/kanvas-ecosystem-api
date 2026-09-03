<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PiDev\Jobs\RetryPiDevJobJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\Connectors\Traits\HasPiDevConfiguration;
use Tests\TestCase;

final class RetryPiDevJobJobTest extends TestCase
{
    use HasPiDevConfiguration;

    /**
     * The retry is the last thing standing between a provider blip and a human. If it cannot even be
     * queued, the task has to land somewhere terminal — a task parked mid-retry with no poller and no
     * blocked_reason is invisible to everyone.
     */
    public function testATaskWhoseRetryCannotBeQueuedIsBlockedRatherThanLeftInLimbo(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);

        // No ConfigureAgentAction — the agent has no GitHub token, so the retry throws before any HTTP.
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::IN_PROGRESS, 'job-dead');

        new RetryPiDevJobJob($app, $task->getId())->handle();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $fresh->status);
        $this->assertNotNull($fresh->blocked_reason);
        $this->assertSame(PlanStatusEnum::FAILED->value, $fresh->plan->status);
    }
}
