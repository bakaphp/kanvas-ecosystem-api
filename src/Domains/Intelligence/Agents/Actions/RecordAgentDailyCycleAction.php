<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;
use Kanvas\Intelligence\Agents\Models\AgentSleepPhase;
use Kanvas\NervousSystem\Ledger\Models\Event;
use RuntimeException;

class RecordAgentDailyCycleAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly Carbon $cycleDate,
    ) {
    }

    public function execute(): AgentDailyCycle
    {
        $dayStart = $this->cycleDate->copy()->startOfDay();
        $dayEnd = $this->cycleDate->copy()->endOfDay();
        $previousNightStart = $dayStart->copy()->subHours(12);

        $events = $this->fetchEvents($previousNightStart, $dayEnd);

        $stateTransitions = $this->stateTransitionTimestamps($events);
        $proactive = $this->countByPrefix($events, 'agent.initiated.');
        $eventsProcessed = $events->count();
        $skills = $this->extractSkills($events);

        $cycle = AgentDailyCycle::updateOrCreate(
            [
                'agent_id' => $this->agent->getId(),
                'cycle_date' => $this->cycleDate->toDateString(),
            ],
            [
                'apps_id' => $this->agent->apps_id,
                'companies_id' => $this->agent->companies_id,
                'awake_started_at' => $stateTransitions['woke_at'],
                'awake_ended_at' => $stateTransitions['next_slept_at'],
                'sleep_started_at' => $stateTransitions['slept_at'],
                'sleep_ended_at' => $stateTransitions['woke_at'],
                'awake_duration_minutes' => $stateTransitions['awake_minutes'],
                'sleep_duration_minutes' => $stateTransitions['sleep_minutes'],
                'proactive_actions_count' => $proactive,
                'events_processed_count' => $eventsProcessed,
                'morning_briefing' => $this->composeBriefing($eventsProcessed, $skills, $this->countSleepPhases($events)),
                'proposed_actions' => $this->extractProposedActions($events),
                'skills_emerged' => $skills,
                'self_improvement_score' => $this->scoreImprovement($skills, $proactive),
                'signed_by_text' => sprintf('— %s, signing in', $this->agent->name),
                'is_deleted' => false,
            ],
        );

        $this->rebuildSleepPhases($cycle, $events);

        $reloaded = $cycle->fresh(['phases']);
        if ($reloaded === null) {
            throw new RuntimeException('Daily cycle vanished between write and reload');
        }

        return $reloaded;
    }

    /**
     * @return Collection<int, Event>
     */
    private function fetchEvents(Carbon $from, Carbon $to): Collection
    {
        return Event::query()
            ->where('apps_id', $this->agent->apps_id)
            ->where('companies_id', $this->agent->companies_id)
            ->where('actor_type', 'Agent')
            ->where('actor_id', $this->agent->getId())
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->get(['event_type', 'status', 'payload', 'occurred_at']);
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return array{woke_at: ?Carbon, slept_at: ?Carbon, next_slept_at: ?Carbon, awake_minutes: int, sleep_minutes: int}
     */
    private function stateTransitionTimestamps(Collection $events): array
    {
        $wokeAt = null;
        $sleptAt = null;
        $nextSleptAt = null;

        foreach ($events as $event) {
            if ($event->event_type === 'agent.woke' && $wokeAt === null) {
                $wokeAt = Carbon::parse($event->occurred_at);
            }
            if ($event->event_type === 'agent.slept') {
                $ts = Carbon::parse($event->occurred_at);
                if ($wokeAt === null && $sleptAt === null) {
                    $sleptAt = $ts;
                } elseif ($wokeAt !== null && $ts->gt($wokeAt)) {
                    $nextSleptAt = $ts;
                }
            }
        }

        $sleepMinutes = ($sleptAt && $wokeAt && $wokeAt->gt($sleptAt))
            ? (int) $sleptAt->diffInMinutes($wokeAt)
            : 0;
        $awakeMinutes = ($wokeAt && $nextSleptAt && $nextSleptAt->gt($wokeAt))
            ? (int) $wokeAt->diffInMinutes($nextSleptAt)
            : ($wokeAt ? (int) $wokeAt->diffInMinutes(now()) : 0);

        return [
            'woke_at' => $wokeAt,
            'slept_at' => $sleptAt,
            'next_slept_at' => $nextSleptAt,
            'awake_minutes' => $awakeMinutes,
            'sleep_minutes' => $sleepMinutes,
        ];
    }

    /**
     * @param Collection<int, Event> $events
     */
    private function countByPrefix(Collection $events, string $prefix): int
    {
        return $events->filter(fn (Event $e): bool => str_starts_with($e->event_type, $prefix))->count();
    }

    /**
     * @param Collection<int, Event> $events
     */
    private function countSleepPhases(Collection $events): int
    {
        return $events->filter(fn (Event $e): bool => $e->event_type === 'agent.sleep.phase_started')->count();
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return array<int, array{name: string, confidence: float}>
     */
    private function extractSkills(Collection $events): array
    {
        $skills = [];
        foreach ($events as $event) {
            if ($event->event_type !== 'agent.skill.emerged') {
                continue;
            }
            $payload = is_array($event->payload) ? $event->payload : [];
            $skills[] = [
                'name' => (string) ($payload['name'] ?? 'unnamed'),
                'confidence' => (float) ($payload['confidence'] ?? 0),
            ];
        }

        return $skills;
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return array<int, string>
     */
    private function extractProposedActions(Collection $events): array
    {
        $latestBriefing = $events
            ->filter(fn (Event $e): bool => $e->event_type === 'agent.briefing.proposed')
            ->last();

        if ($latestBriefing === null) {
            return [];
        }

        $items = is_array($latestBriefing->payload) ? ($latestBriefing->payload['actions'] ?? []) : [];

        return is_array($items) ? array_map('strval', $items) : [];
    }

    /**
     * @param array<int, array{name: string, confidence: float}> $skills
     */
    private function composeBriefing(int $eventsProcessed, array $skills, int $phaseCount): string
    {
        if ($eventsProcessed === 0) {
            return 'Good morning. Quiet cycle — no signals processed and no new patterns surfaced. Standing by for inbound.';
        }

        $skillLine = '';
        if ($skills !== []) {
            $first = $skills[0]['name'];
            $skillLine = sprintf(' One new skill emerged: "%s".', $first);
        }

        return sprintf(
            'Good morning. Last cycle I processed %d events across %d sleep phase%s.%s',
            $eventsProcessed,
            $phaseCount,
            $phaseCount === 1 ? '' : 's',
            $skillLine,
        );
    }

    /**
     * @param array<int, array{name: string, confidence: float}> $skills
     */
    private function scoreImprovement(array $skills, int $proactive): float
    {
        // Light heuristic for v1: each emergent skill is worth 0.04, each proactive
        // action 0.001. Bounded at 0.5 to keep daily deltas in a believable range.
        $score = (count($skills) * 0.04) + ($proactive * 0.001);

        return min(0.5, round($score, 3));
    }

    /**
     * @param Collection<int, Event> $events
     */
    private function rebuildSleepPhases(AgentDailyCycle $cycle, Collection $events): void
    {
        // Hard delete — SoftDeletesTrait would only flip is_deleted, leaving
        // stale rows behind that would double up on every cron re-run.
        DB::connection('intelligence')
            ->table('agent_sleep_phases')
            ->where('agent_daily_cycle_id', $cycle->getKey())
            ->delete();

        $phaseEvents = $events
            ->filter(fn (Event $e): bool => $e->event_type === 'agent.sleep.phase_started')
            ->values();
        $count = $phaseEvents->count();

        foreach ($phaseEvents as $i => $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $phase = (string) ($payload['phase'] ?? 'light');
            $startedAt = Carbon::parse($event->occurred_at);
            $endedAt = $i + 1 < $count
                ? Carbon::parse($phaseEvents[$i + 1]->occurred_at)
                : ($cycle->sleep_ended_at ?? $startedAt);
            $duration = (int) max(0, $startedAt->diffInMinutes($endedAt));

            AgentSleepPhase::create([
                'apps_id' => $this->agent->apps_id,
                'agent_daily_cycle_id' => $cycle->getKey(),
                'agent_id' => $this->agent->getId(),
                'phase' => $phase,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_minutes' => $duration,
                'outcome_summary' => (string) ($payload['outcome'] ?? ''),
                'payload' => $payload,
                'is_deleted' => false,
            ]);
        }
    }
}
