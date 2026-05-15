<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;

class RefreshAgentLiveCountersAction
{
    public function __construct(
        protected readonly Agent $agent,
    ) {
    }

    /**
     * Returns the refreshed cycle, or null when today's row doesn't exist yet
     * (caller can skip — the daily cron will create it tomorrow morning).
     */
    public function execute(): ?AgentDailyCycle
    {
        $cycle = AgentDailyCycle::query()
            ->where('agent_id', $this->agent->getId())
            ->where('cycle_date', now()->toDateString())
            ->first();

        if ($cycle === null || $cycle->awake_started_at === null) {
            return $cycle;
        }

        $windowStart = $cycle->awake_started_at;
        $windowEnd = $cycle->awake_ended_at ?? now();
        $awakeMinutes = (int) $windowStart->diffInMinutes($windowEnd);

        // Two COUNTs on the same (actor_type, actor_id, occurred_at) index —
        // no row reads, no payload parsing. Cheap to run on every active agent.
        $base = fn () => DB::connection('intelligence')
            ->table('nervous_system_events')
            ->where('apps_id', $this->agent->apps_id)
            ->where('companies_id', $this->agent->companies_id)
            ->where('actor_type', 'Agent')
            ->where('actor_id', $this->agent->getId())
            ->whereBetween('occurred_at', [$windowStart, $windowEnd]);

        $eventsCount = $base()->count();
        $proactiveCount = $base()->where('event_type', 'like', 'agent.initiated.%')->count();

        // The most recent event in the awake window — drives the dashboard
        // "LAST ACTION 3m ago" card. Fetches one row, picks payload.description
        // as the human-readable label so the UI doesn't have to humanize an
        // event_type string.
        $lastEvent = $base()
            ->orderBy('occurred_at', 'desc')
            ->limit(1)
            ->first(['event_type', 'payload', 'occurred_at']);

        $lastActionAt = $lastEvent ? $lastEvent->occurred_at : null;
        $lastActionLabel = $this->labelFromEvent($lastEvent);

        // Update only the volatile columns. Narrative fields (morning_briefing,
        // proposed_actions, skills_emerged, signed_by_text) and the sleep_phase
        // log are owned by the daily cron and stay untouched.
        $cycle->update([
            'events_processed_count' => $eventsCount,
            'proactive_actions_count' => $proactiveCount,
            'awake_duration_minutes' => $awakeMinutes,
            'last_action_at' => $lastActionAt,
            'last_action_label' => $lastActionLabel,
        ]);

        return $cycle->fresh();
    }

    private function labelFromEvent(?object $event): ?string
    {
        if ($event === null) {
            return null;
        }

        $payload = is_string($event->payload) ? json_decode($event->payload, true) : $event->payload;
        if (is_array($payload) && isset($payload['description']) && is_string($payload['description'])) {
            return $payload['description'];
        }

        // Fall back to a humanized form of the event_type
        // ("agent.initiated.lead_assignment" → "Initiated · lead assignment").
        $parts = explode('.', (string) $event->event_type);
        if (count($parts) >= 3) {
            return ucfirst($parts[1]) . ' · ' . str_replace('_', ' ', $parts[2]);
        }

        return (string) $event->event_type;
    }
}
