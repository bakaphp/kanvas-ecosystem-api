<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\LaunchTaskSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\PollHarnessSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\VerifyHarnessDiffJob;
use PHPUnit\Framework\Attributes\Group;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Production, 2026-11-03: three coding tasks in a row sat in "starting" with no machine, no container
 * and no error until the sweeper reaped them at ten minutes, and the retry launched at once. The
 * launch job had been queued on `agent-runtime` behind deployment builds and backups, ran late, found
 * its session closed and returned silently — so the rows said nothing and the PM read "no activity"
 * as a hung runner.
 */
#[Group('serial')]
class CodingLaunchQueueTest extends TestCase
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

    public function test_the_harness_jobs_ride_their_own_queue(): void
    {
        $app = app(Apps::class);

        $this->assertSame('coding', new LaunchTaskSessionJob($app, 1, 'brief')->queue);
        $this->assertSame('coding', new PollHarnessSessionJob($app, 1)->queue);
        $this->assertSame('coding', new VerifyHarnessDiffJob($app, 1)->queue);
    }

    public function test_a_launch_that_arrives_after_its_session_was_closed_says_so_on_the_row(): void
    {
        [, , $session] = $this->delegatedCodingJob(HarnessStatusEnum::CANCELLED, branch: null, attributes: [
            'error_message' => 'The launch never started within 10 minutes — the coding queue did not pick it up; nothing ran on a machine.',
        ]);
        $session->created_at = Carbon::now()->subMinutes(14);
        $session->saveQuietly();

        new LaunchTaskSessionJob(app(Apps::class), $session->getId(), 'Fix the login redirect')->handle();

        $session->refresh();
        $this->assertSame(HarnessStatusEnum::CANCELLED->value, $session->status, 'a late launch must not reopen the session');
        $this->assertNull($session->agent_machine_id, 'a late launch must not provision anything');
        $this->assertStringStartsWith('The launch never started', (string) $session->error_message);
        $this->assertStringContainsString('Launch job ran at', (string) $session->error_message);
        $this->assertStringContainsString('14 min after dispatch', (string) $session->error_message);
        $this->assertStringContainsString('already cancelled', (string) $session->error_message);
    }

    public function test_the_sweeper_tells_a_launch_that_never_ran_apart_from_a_run_that_went_quiet(): void
    {
        [, , $neverLaunched] = $this->delegatedCodingJob(HarnessStatusEnum::STARTING, branch: null, attributes: [
            'heartbeat_at' => Carbon::now()->subMinutes(11),
        ]);
        [, , $wentQuiet] = $this->delegatedCodingJob(HarnessStatusEnum::RUNNING, branch: null, attributes: [
            'heartbeat_at' => Carbon::now()->subMinutes(11),
            'endpoint' => 'http://127.0.0.1:4096',
        ]);

        $this->artisan('kanvas:coding:sweep-sessions')->assertSuccessful();

        $this->assertSame(HarnessStatusEnum::CANCELLED->value, $neverLaunched->refresh()->status);
        $this->assertStringStartsWith('The launch never started within 10 minutes', (string) $neverLaunched->error_message);
        $this->assertSame(HarnessStatusEnum::CANCELLED->value, $wentQuiet->refresh()->status);
        $this->assertStringStartsWith('No activity for 10 minutes', (string) $wentQuiet->error_message);
    }
}
