<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * The proactive pulse. For each open project whose cadence has elapsed it decides "does this need a
 * nudge?" (stalled work, or pending work with nothing in flight) and, if so, wakes the PM — the
 * driver that keeps in-process agents advancing work even when nothing external happened. Container
 * agents self-drive (their own cron), so they're skipped. Stateless: it re-derives attention from
 * live state; the only thing it persists is the anti-thrash cadence.
 */
class ProjectHeartbeatService
{
    private const int STALL_MINUTES = 30;

    /**
     * Open projects to evaluate this run. Normally only those whose cadence has elapsed; $ignoreCadence
     * (the command's --force) drops that timer so every open project is evaluated now, and $projectId
     * narrows it to a single project — both for manual/testing triggers.
     *
     * @return Collection<int, Project>
     */
    public function dueProjects(Apps $app, bool $ignoreCadence = false, ?int $projectId = null): Collection
    {
        return Project::query()
            ->fromApp($app)
            ->notDeleted()
            ->whereIn('status', $this->openStatusValues())
            ->when(
                $projectId !== null,
                fn (Builder $query): Builder => $query->where('id', $projectId),
            )
            ->when(
                ! $ignoreCadence,
                fn (Builder $query): Builder => $query->where(
                    fn (Builder $inner): Builder => $inner
                        ->whereNull('next_heartbeat_at')
                        ->orWhere('next_heartbeat_at', '<=', now()),
                ),
            )
            ->get();
    }

    /**
     * Evaluate + (maybe) wake, then advance the cadence. Returns whether the PM was woken. $forceWake
     * (the command's --force) wakes the PM even with nothing obviously waiting — a manual trigger —
     * but still never double-drives a container-runtime PM.
     */
    public function tick(Project $project, bool $forceWake = false): bool
    {
        $needsAttention = $this->needsAttention($project);
        $woke = false;

        if (($needsAttention || $forceWake) && ! $this->pmSelfDrives($project)) {
            WakeAgentForProjectJob::dispatch($project, WakeAgentForProjectJob::REASON_HEARTBEAT);
            $woke = true;
        }

        // Anti-thrash: advance the cadence whether or not we woke, so a busy-but-quiet project isn't
        // re-evaluated every 5-min scheduler run.
        $project->last_heartbeat_at = now();
        $project->next_heartbeat_at = now()->addMinutes($project->heartbeat_interval_minutes);
        $project->saveQuietly();

        $project->emitLedgerEvent('project.heartbeat.tick', payload: [
            'needs_attention' => $needsAttention,
            'woke' => $woke,
            'forced' => $forceWake,
        ]);

        return $woke;
    }

    /**
     * Is there work that's waiting or stuck? A project with no plans yet doesn't get nudged — it waits
     * for ingest to give it something; the heartbeat is for *existing* work that isn't moving.
     */
    public function needsAttention(Project $project): bool
    {
        // A worker that blocked its plan (e.g. "I don't have the tools to do this") is waiting on the
        // PM to reassign/escalate — pick it up even if it left no pending subtasks behind.
        $hasBlockedPlan = $project->plans()
            ->where('status', PlanStatusEnum::BLOCKED->value)
            ->exists();
        if ($hasBlockedPlan) {
            return true;
        }

        $planIds = $project->plans()->pluck('id')->all();
        if ($planIds === []) {
            return false;
        }

        $hasStalled = Task::query()
            ->whereIn('plan_id', $planIds)
            ->stalled(self::STALL_MINUTES)
            ->exists();
        if ($hasStalled) {
            return true;
        }

        $tasks = Task::query()
            ->whereIn('plan_id', $planIds)
            ->notDeleted()
            ->get();

        $hasInFlight = $tasks->contains(fn (Task $task): bool => $task->status === TaskStatusEnum::IN_PROGRESS->value);
        $hasPending = $tasks->contains(fn (Task $task): bool => $task->status === TaskStatusEnum::PENDING->value);

        return $hasPending && ! $hasInFlight;
    }

    /**
     * Container-runtime PMs (Hermes/OpenClaw) re-invoke themselves via their in-image cron — the
     * project heartbeat leaves them alone rather than double-driving.
     */
    private function pmSelfDrives(Project $project): bool
    {
        $agent = $project->pmAgent;

        return $agent !== null && $agent->isContainerRuntime();
    }

    /**
     * @return array<int, string>
     */
    private function openStatusValues(): array
    {
        return array_map(
            fn (ProjectStatusEnum $status): string => $status->value,
            ProjectStatusEnum::openStatuses(),
        );
    }
}
