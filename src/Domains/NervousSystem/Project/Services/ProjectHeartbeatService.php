<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
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
 * agents self-drive (their own cron), so they're skipped. It re-derives attention from live state;
 * what it persists is the cadence and the backoff for a stuck state the PM has already been woken for.
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
        return $this->dueQuery($ignoreCadence, $projectId)->fromApp($app)->get();
    }

    /**
     * The apps that own a due project — so a caller only rebinds scope for apps with candidates. Shares
     * dueQuery() with dueProjects() so the command and service can't drift on "which projects are due".
     *
     * @return SupportCollection<int, int>
     */
    public function candidateAppIds(bool $ignoreCadence = false, ?int $projectId = null): SupportCollection
    {
        return $this->dueQuery($ignoreCadence, $projectId)->distinct()->pluck('apps_id');
    }

    private function dueQuery(bool $ignoreCadence, ?int $projectId): Builder
    {
        return Project::query()
            ->notDeleted()
            ->whereIn('status', ProjectStatusEnum::openStatusValues())
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
            );
    }

    /**
     * Evaluate + (maybe) wake, then advance the cadence. Returns whether the PM was woken. $forceWake
     * (the command's --force) wakes the PM even with nothing obviously waiting — a manual trigger —
     * but still never double-drives a container-runtime PM, and leaves the backoff state alone.
     */
    public function tick(Project $project, bool $forceWake = false): bool
    {
        $fingerprint = $this->attentionFingerprint($project);
        $needsAttention = $fingerprint !== null;
        $changed = $fingerprint !== $project->heartbeat_attention_hash;
        $backoffAllows = $needsAttention && $this->backoffAllowsWake($project, $changed);
        $woke = false;

        if (($backoffAllows || $forceWake) && ! $this->pmSelfDrives($project)) {
            WakeAgentForProjectJob::dispatch($project, WakeAgentForProjectJob::REASON_HEARTBEAT);
            $woke = true;
        }

        if ($backoffAllows || ! $needsAttention) {
            $this->advanceBackoff($project, $fingerprint, $changed);
        }

        // Anti-thrash: advance the cadence whether or not we woke, so a busy-but-quiet project isn't
        // re-evaluated every 5-min scheduler run. Evaluating is a few indexed queries; the backoff is
        // what spaces out the wakes, so a change is still noticed at the normal cadence.
        $project->last_heartbeat_at = now();
        $project->next_heartbeat_at = now()->addMinutes($project->heartbeat_interval_minutes);
        $project->saveQuietly();

        $project->emitLedgerEvent('project.heartbeat.tick', payload: [
            'needs_attention' => $needsAttention,
            'woke' => $woke,
            'forced' => $forceWake,
            'attention_changed' => $needsAttention && $changed,
            'backoff_level' => $project->heartbeat_backoff_level,
            'backoff_until' => $project->heartbeat_backoff_until?->toIso8601String(),
        ]);

        return $woke;
    }

    public function needsAttention(Project $project): bool
    {
        return $this->attentionFingerprint($project) !== null;
    }

    /**
     * Identifies the work that's waiting or stuck, or null when nothing is. A project with no plans yet
     * doesn't get nudged — it waits for ingest to give it something; the heartbeat is for *existing*
     * work that isn't moving.
     *
     * Built from ids and statuses only, never timestamps: a PM that touches a stuck task without moving
     * it must not read as progress, or it would reset its own backoff on every wake.
     */
    public function attentionFingerprint(Project $project): ?string
    {
        // A worker that blocked its plan (e.g. "I don't have the tools to do this") is waiting on the
        // PM to reassign/escalate — pick it up even if it left no pending subtasks behind.
        $blockedPlanIds = $project->plans()
            ->where('status', PlanStatusEnum::BLOCKED->value)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $planIds = $project->plans()->pluck('id')->all();
        if ($planIds === []) {
            return null;
        }

        $stalledTaskIds = Task::query()
            ->whereIn('plan_id', $planIds)
            ->stalled(self::STALL_MINUTES)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $idleTaskIds = $this->pendingTaskIdsWithNothingInFlight($planIds);

        if ($blockedPlanIds === [] && $stalledTaskIds === [] && $idleTaskIds === []) {
            return null;
        }

        return sha1(implode('|', [
            'blocked:' . implode(',', $blockedPlanIds),
            'stalled:' . implode(',', $stalledTaskIds),
            'idle:' . implode(',', $idleTaskIds),
        ]));
    }

    /**
     * @param list<int> $planIds
     *
     * @return list<int>
     */
    private function pendingTaskIdsWithNothingInFlight(array $planIds): array
    {
        $active = Task::query()
            ->whereIn('plan_id', $planIds)
            ->notDeleted()
            ->whereIn('status', [TaskStatusEnum::PENDING->value, TaskStatusEnum::IN_PROGRESS->value])
            ->orderBy('id')
            ->get(['id', 'status']);

        if ($active->contains('status', TaskStatusEnum::IN_PROGRESS->value)) {
            return [];
        }

        return $active->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * A new stuck state always wakes. The same one re-wakes only once its backoff window has passed —
     * the PM already saw it, and a turn that changed nothing is unlikely to be fixed by an identical one.
     */
    private function backoffAllowsWake(Project $project, bool $changed): bool
    {
        if ($changed || $project->heartbeatBackoffSteps() === []) {
            return true;
        }

        return $project->heartbeat_backoff_until === null || $project->heartbeat_backoff_until->isPast();
    }

    private function advanceBackoff(Project $project, ?string $fingerprint, bool $changed): void
    {
        $steps = $project->heartbeatBackoffSteps();

        if ($fingerprint === null || $steps === []) {
            $project->heartbeat_attention_hash = $fingerprint;
            $project->heartbeat_backoff_level = 0;
            $project->heartbeat_backoff_until = null;

            return;
        }

        $level = $changed ? 0 : min($project->heartbeat_backoff_level + 1, count($steps) - 1);

        $project->heartbeat_attention_hash = $fingerprint;
        $project->heartbeat_backoff_level = $level;
        $project->heartbeat_backoff_until = now()->addMinutes($steps[$level]);
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
}
