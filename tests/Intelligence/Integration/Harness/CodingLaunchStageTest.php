<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Jobs\WarmRepositoryMirrorJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\LaunchStageEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A launch is minutes of silence by design — a first mirror clone has a 900-second budget and git says
 * nothing while it works. The stage on the row is what lets the sweeper wait, and what the PM reads
 * instead of calling a healthy clone "stuck".
 */
#[Group('serial')]
class CodingLaunchStageTest extends TestCase
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

        $app = app(Apps::class);
        $this->originalEndpoint = $app->get(ConfigurationEnum::STATIC_ENDPOINT->value);
        $app->set(ConfigurationEnum::STATIC_ENDPOINT->value, null);
    }

    protected function tearDown(): void
    {
        app(Apps::class)->set(ConfigurationEnum::STATIC_ENDPOINT->value, $this->originalEndpoint);
        parent::tearDown();
    }

    public function test_marking_a_stage_is_progress_and_touches_the_heartbeat(): void
    {
        $session = $this->createTaskSession(HarnessStatusEnum::STARTING, ['heartbeat_at' => Carbon::now()->subMinutes(9)]);

        $session->markLaunchStage(LaunchStageEnum::PREPARING_WORKTREE);

        $session->refresh();
        $this->assertSame(LaunchStageEnum::PREPARING_WORKTREE, $session->launchStage());
        $this->assertSame(HarnessStatusEnum::STARTING->value, $session->status);
        $this->assertLessThan(5, $session->secondsSinceHeartbeat());
    }

    public function test_the_sweeper_waits_the_clone_budget_for_a_launch_preparing_its_worktree(): void
    {
        [, , $cloning] = $this->delegatedCodingJob(HarnessStatusEnum::STARTING, branch: null, attributes: [
            'launch_stage' => LaunchStageEnum::PREPARING_WORKTREE->value,
            'heartbeat_at' => Carbon::now()->subMinutes(12),
        ]);
        [, , $resolving] = $this->delegatedCodingJob(HarnessStatusEnum::STARTING, branch: null, attributes: [
            'launch_stage' => LaunchStageEnum::RESOLVING_REPOSITORY->value,
            'heartbeat_at' => Carbon::now()->subMinutes(12),
        ]);

        $this->artisan('kanvas:coding:sweep-sessions')->assertSuccessful();

        $this->assertSame(HarnessStatusEnum::STARTING->value, $cloning->refresh()->status, 'a clone is silent for up to 15 minutes by design');
        $this->assertSame(HarnessStatusEnum::CANCELLED->value, $resolving->refresh()->status, 'resolving a repository is seconds; 12 silent minutes is a stall');
        $this->assertStringStartsWith('The launch stalled while resolving the repository', (string) $resolving->error_message);
    }

    public function test_a_clone_silent_past_its_budget_is_swept_with_the_stage_in_the_reason(): void
    {
        [, , $session] = $this->delegatedCodingJob(HarnessStatusEnum::STARTING, branch: null, attributes: [
            'launch_stage' => LaunchStageEnum::PREPARING_WORKTREE->value,
            'heartbeat_at' => Carbon::now()->subMinutes(17),
        ]);

        $this->artisan('kanvas:coding:sweep-sessions')->assertSuccessful();

        $this->assertSame(HarnessStatusEnum::CANCELLED->value, $session->refresh()->status);
        $this->assertSame(
            'The launch stalled while preparing the worktree — no progress for 16 minutes.',
            $session->error_message
        );
    }

    public function test_the_warm_job_rides_the_coding_queue_and_does_nothing_for_an_unknown_slug(): void
    {
        $plan = $this->makePlan();
        $job = new WarmRepositoryMirrorJob($plan->agent, 'not-on-the-list');

        $this->assertSame('coding', $job->queue);

        $job->handle();

        $this->addToAssertionCount(1);
    }
}
