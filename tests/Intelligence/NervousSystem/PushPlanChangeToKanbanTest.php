<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Jobs\PushPlanToKanbanJob;
use Kanvas\NervousSystem\Plan\Jobs\PushTaskToKanbanJob;
use Kanvas\NervousSystem\Plan\Listeners\PushPlanChangeToKanban;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Mockery;
use Tests\TestCase;

/**
 * The push listener's gate: sync-origin events are ignored (loop guard), and only agents with a
 * running Hermes deployment push. CREATED/UPDATED → plan job; nothing dispatched otherwise.
 */
final class PushPlanChangeToKanbanTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testFromSyncEventIsIgnored(): void
    {
        Bus::fake();

        $event = new PlanBroadcast(
            $this->planWithRunningHermes(),
            PlanChangeTypeEnum::CREATED,
            fromSync: true,
        );

        new PushPlanChangeToKanban()->handle($event);

        Bus::assertNotDispatched(PushPlanToKanbanJob::class);
        Bus::assertNotDispatched(PushTaskToKanbanJob::class);
    }

    public function testPlanCreatedOnRunningHermesAgentDispatchesPushPlanJob(): void
    {
        Bus::fake();

        $event = new PlanBroadcast(
            $this->planWithRunningHermes(),
            PlanChangeTypeEnum::CREATED,
        );

        new PushPlanChangeToKanban()->handle($event);

        Bus::assertDispatched(PushPlanToKanbanJob::class);
    }

    public function testAgentlessPlanDispatchesNothing(): void
    {
        Bus::fake();

        $plan = Mockery::mock(Plan::class)->makePartial();
        $plan->shouldReceive('getAttribute')->with('agent')->andReturn(null);

        new PushPlanChangeToKanban()->handle(
            new PlanBroadcast($plan, PlanChangeTypeEnum::CREATED),
        );

        Bus::assertNotDispatched(PushPlanToKanbanJob::class);
    }

    private function planWithRunningHermes(): Plan
    {
        $deployment = Mockery::mock(AgentDeployment::class)->makePartial();
        $deployment->shouldReceive('isRunning')->andReturn(true);
        $deployment->shouldReceive('getAttribute')->with('provider')->andReturn('hermes');

        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('getAttribute')->with('activeDeployment')->andReturn($deployment);

        $plan = Mockery::mock(Plan::class)->makePartial();
        $plan->shouldReceive('getAttribute')->with('agent')->andReturn($agent);

        return $plan;
    }
}
