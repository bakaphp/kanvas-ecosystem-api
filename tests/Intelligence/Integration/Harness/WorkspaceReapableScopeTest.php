<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Tests\TestCase;

/**
 * What the reaper is allowed to delete.
 *
 * Covered here rather than through the action because the action's first line opens an SSH connection
 * to a real machine — the selection is the part with the bugs in it, and it is the part a mistake is
 * unrecoverable in: every row this scope returns gets `rm -rf`.
 */
class WorkspaceReapableScopeTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const int MACHINE = 987654;
    private const int RETENTION = 24;

    public function testEndedLongAgoIsReapable(): void
    {
        $session = $this->makeSession(HarnessStatusEnum::COMPLETED, completedAt: Carbon::now()->subHours(30));

        $this->assertTrue($this->reapable()->contains('id', $session->getId()));
    }

    public function testStillInsideTheRetentionWindowIsLeftAlone(): void
    {
        $session = $this->makeSession(HarnessStatusEnum::COMPLETED, completedAt: Carbon::now()->subHours(2));

        $this->assertFalse($this->reapable()->contains('id', $session->getId()));
    }

    public function testRunningSessionIsNeverReapable(): void
    {
        $session = $this->makeSession(HarnessStatusEnum::RUNNING, completedAt: null);
        AgentTaskSession::query()->whereKey($session->getId())
            ->update(['updated_at' => Carbon::now()->subHours(30)]);

        $this->assertFalse($this->reapable()->contains('id', $session->getId()));
    }

    /**
     * The bug this scope was rewritten for: a failed run frequently never reaches the code that stamps
     * `completed_at`, so filtering on that column left two thirds of our failed sessions holding their
     * workspace forever — and a failed run's directory is the biggest one on the disk.
     */
    public function testFailedSessionWithNoCompletedAtIsStillReapable(): void
    {
        $session = $this->makeSession(HarnessStatusEnum::FAILED, completedAt: null);
        AgentTaskSession::query()->whereKey($session->getId())
            ->update(['updated_at' => Carbon::now()->subHours(30)]);

        $this->assertTrue($this->reapable()->contains('id', $session->getId()));
    }

    /**
     * Without this the reaper re-selects the same rows every run and re-issues `rm -rf` for paths it
     * deleted days ago, forever.
     */
    public function testAlreadyReapedIsNotReturnedAgain(): void
    {
        $session = $this->makeSession(HarnessStatusEnum::COMPLETED, completedAt: Carbon::now()->subHours(30));
        $session->workspace_reaped_at = Carbon::now();
        $session->saveQuietly();

        $this->assertFalse($this->reapable()->contains('id', $session->getId()));
    }

    public function testAttachModeSessionWithNoWorkspaceIsSkipped(): void
    {
        $session = $this->makeSession(
            HarnessStatusEnum::COMPLETED,
            completedAt: Carbon::now()->subHours(30),
            workspace: null
        );

        $this->assertFalse($this->reapable()->contains('id', $session->getId()));
    }

    /**
     * A machine can host more than one harness. The docker label names an agent, not a runtime, so the
     * harness filter is what stops this sweep deleting another runtime's work off a shared box.
     */
    public function testAnotherHarnessSessionIsNotTouched(): void
    {
        $session = $this->makeSession(
            HarnessStatusEnum::COMPLETED,
            completedAt: Carbon::now()->subHours(30),
            harness: HarnessEnum::PIDEV
        );

        $this->assertFalse($this->reapable()->contains('id', $session->getId()));
    }

    public function testAnotherMachinesSessionIsNotTouched(): void
    {
        $session = $this->makeSession(
            HarnessStatusEnum::COMPLETED,
            completedAt: Carbon::now()->subHours(30),
            machineId: self::MACHINE + 1
        );

        $this->assertFalse($this->reapable()->contains('id', $session->getId()));
    }

    /**
     * The batch is capped, so the order decides which rows are reachable at all. Unordered, the tail of
     * a large backlog is chosen by the database and can sit unreaped indefinitely.
     */
    public function testOldestIsReturnedFirst(): void
    {
        $newer = $this->makeSession(HarnessStatusEnum::COMPLETED, completedAt: Carbon::now()->subHours(26));
        $older = $this->makeSession(HarnessStatusEnum::COMPLETED, completedAt: Carbon::now()->subHours(90));

        $ids = $this->reapable()->pluck('id')->all();

        $this->assertLessThan(
            array_search($newer->getId(), $ids, true),
            array_search($older->getId(), $ids, true)
        );
    }

    /**
     * @return Collection<int, AgentTaskSession>
     */
    private function reapable(): Collection
    {
        return AgentTaskSession::query()
            ->workspaceReapable(self::MACHINE, self::RETENTION, HarnessEnum::OPENCODE)
            ->get();
    }

    private function makeSession(
        HarnessStatusEnum $status,
        ?Carbon $completedAt,
        ?string $workspace = '/srv/kanvas/agents/1/worktrees/x',
        ?int $machineId = null,
        HarnessEnum $harness = HarnessEnum::OPENCODE,
    ): AgentTaskSession {
        $app = app(Apps::class);

        $session = new AgentTaskSession();
        $session->apps_id = $app->getId();
        $session->companies_id = 0;
        $session->task_id = random_int(900000, 999999);
        $session->harness = $harness->value;
        $session->status = $status->value;
        $session->agent_machine_id = $machineId ?? self::MACHINE;
        $session->workspace_path = $workspace;
        $session->completed_at = $completedAt;
        $session->saveOrFail();

        return $session;
    }
}
