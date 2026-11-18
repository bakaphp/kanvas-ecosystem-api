<?php

declare(strict_types=1);

namespace Tests\Workflow\Unit;

use Illuminate\Contracts\Queue\Job;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Mockery;
use Tests\TestCaseUnit;

/**
 * 2026-11-18: 14 rule workflows that could never take their turn released themselves ~30 times a
 * second for ten hours — 7M releases that starved the default queue and quadrupled Redis CPU.
 */
final class DynamicRuleWorkflowReleaseBackoffTest extends TestCaseUnit
{
    public function testTheFirstReleasesKeepTheEnginesZeroDelay(): void
    {
        $this->assertSame(0, DynamicRuleWorkflow::releaseDelayFor(1));
        $this->assertSame(0, DynamicRuleWorkflow::releaseDelayFor(25));
    }

    public function testAJobThatKeepsBeingReleasedSlowsDown(): void
    {
        $this->assertSame(5, DynamicRuleWorkflow::releaseDelayFor(26));
        $this->assertSame(5, DynamicRuleWorkflow::releaseDelayFor(200));
        $this->assertSame(60, DynamicRuleWorkflow::releaseDelayFor(201));
        $this->assertSame(60, DynamicRuleWorkflow::releaseDelayFor(500_000));
    }

    public function testAnUndelayedReleaseFromTheEngineIsHeldBackOnceTheJobIsSpinning(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->andReturn(300);
        $job->shouldReceive('release')->once()->with(60);

        $workflow = new DynamicRuleWorkflow(StoredWorkflow::make());
        $workflow->setJob($job);

        $workflow->release();
    }

    public function testALongerDelayAskedForByTheCallerWins(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->andReturn(30);
        $job->shouldReceive('release')->once()->with(90);

        $workflow = new DynamicRuleWorkflow(StoredWorkflow::make());
        $workflow->setJob($job);

        $workflow->release(90);
    }
}
