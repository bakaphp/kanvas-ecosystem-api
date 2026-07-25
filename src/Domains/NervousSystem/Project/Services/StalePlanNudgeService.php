<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Messages\Models\Message;

/**
 * Finds open plans that have gone silent — no message, task movement, or plan change for longer than
 * the threshold. Prolonged silence on committed work means it's forgotten, blocked-but-uncommented, or
 * (for agent-owned plans) the agent stopped and never reported. Detection only; NudgeInactivePlanAction
 * decides what to do about each one.
 */
class StalePlanNudgeService
{
    /**
     * Statuses worth nudging: committed, in-flight work. Draft (not started) and done/cancelled
     * (finished) are intentionally excluded — silence there isn't a problem.
     *
     * @var list<PlanStatusEnum>
     */
    private const array NUDGEABLE_STATUSES = [
        PlanStatusEnum::ACTIVE,
        PlanStatusEnum::BLOCKED,
        PlanStatusEnum::AWAITING_APPROVAL,
    ];

    /**
     * Open, project-bound plans in this app whose last activity is older than $thresholdHours.
     *
     * @return Collection<int, Plan>
     */
    public function stalePlans(Apps $app, int $thresholdHours, ?int $projectId = null): Collection
    {
        $cutoff = now()->subHours($thresholdHours);

        return Plan::query()
            ->fromApp($app)
            ->notDeleted()
            ->whereNotNull('project_id')
            ->whereIn('status', $this->nudgeableStatusValues())
            ->when(
                $projectId !== null,
                fn (Builder $query): Builder => $query->where('project_id', $projectId),
            )
            ->get()
            ->filter(fn (Plan $plan): bool => $this->lastActivityAt($plan)->lessThan($cutoff))
            ->values();
    }

    /**
     * The most recent REAL sign of life on a plan — human-or-agent communication and genuine task
     * progress. Deliberately NOT `plan.updated_at` (nor task `updated_at`): those bump on any backend
     * touch — a reassignment, a completion_pct recompute, the cache-clear observer — none of which means
     * anyone is engaged. Counting them masks a plan that's actually gone silent (the 6846 case: fresh
     * updated_at, but no message in 9h). Signals used: a message on the plan's activity channel, and a
     * task status transition (started_at/completed_at). Floors at when the plan was created.
     */
    public function lastActivityAt(Plan $plan): CarbonInterface
    {
        /** @var CarbonInterface $latest */
        $latest = $plan->created_at;

        // Real task progress = a status transition, not an arbitrary field edit.
        foreach (['started_at', 'completed_at'] as $column) {
            $timestamp = $plan->tasks()->max($column);
            if ($timestamp !== null) {
                $latest = $this->newer($latest, Carbon::parse((string) $timestamp));
            }
        }

        // The activity channel — every comment, agent reply, status note and @mention lands here.
        $channelIds = $plan->socialChannels()->pluck('id')->all();
        if ($channelIds !== []) {
            $messageTimestamp = Message::query()
                ->whereHas('channels', fn (Builder $query): Builder => $query->whereIn('channels.id', $channelIds))
                ->max('created_at');

            if ($messageTimestamp !== null) {
                $latest = $this->newer($latest, Carbon::parse((string) $messageTimestamp));
            }
        }

        return $latest;
    }

    private function newer(CarbonInterface $a, CarbonInterface $b): CarbonInterface
    {
        return $a->greaterThanOrEqualTo($b) ? $a : $b;
    }

    /**
     * @return list<string>
     */
    private function nudgeableStatusValues(): array
    {
        return array_map(fn (PlanStatusEnum $status): string => $status->value, self::NUDGEABLE_STATUSES);
    }
}
