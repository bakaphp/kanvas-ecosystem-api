<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * Swarm-level analog of AgentDailyCycle. One row per (swarm, day) populated
 * overnight by RecordSwarmDailyCycleAction after the per-agent cycles
 * materialize. Drives the "Mission briefing" panel.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agent_swarm_id
 * @property Carbon $cycle_date
 * @property Carbon $generated_at
 * @property int $members_active_count
 * @property int $members_idle_count
 * @property int $events_processed_count
 * @property int $proactive_actions_count
 * @property string|null $cost_usd_today
 * @property string|null $mission_progress_pct
 * @property string|null $progress_delta_since_yesterday
 * @property string|null $bottleneck_summary
 * @property array|null $proposed_options
 * @property array|null $emergent_patterns
 * @property string|null $briefing_text
 * @property string|null $signed_by_text
 * @property string|null $self_improvement_score
 * @property bool $is_deleted
 */
class AgentSwarmDailyCycle extends BaseModel
{
    use UuidTrait;

    protected $table = 'agent_swarm_daily_cycles';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'agent_swarm_id' => 'integer',
            'cycle_date' => 'date',
            'generated_at' => 'datetime',
            'members_active_count' => 'integer',
            'members_idle_count' => 'integer',
            'events_processed_count' => 'integer',
            'proactive_actions_count' => 'integer',
            'proposed_options' => Json::class,
            'emergent_patterns' => Json::class,
            'is_deleted' => 'boolean',
        ];
    }

    public function swarm(): BelongsTo
    {
        return $this->belongsTo(AgentSwarm::class, 'agent_swarm_id', 'id');
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('cycle_date', $date);
    }

    /**
     * Normalize the JSON column into the GraphQL SwarmProposedOption shape.
     * Always returns an array (never null) so the GraphQL `[...!]!` field
     * doesn't crash on a fresh cycle that hasn't synthesized options yet.
     *
     * @return array<int, array{label: string, eta_hours: ?float, impact: ?string}>
     */
    public function getProposedOptionsList(): array
    {
        if (! is_array($this->proposed_options)) {
            return [];
        }
        $out = [];
        foreach ($this->proposed_options as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'label' => (string) ($row['label'] ?? ''),
                'eta_hours' => isset($row['eta_hours']) ? (float) $row['eta_hours'] : null,
                'impact' => isset($row['impact']) ? (string) $row['impact'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{name: string, confidence: ?float, surfaced_in_member_count: ?int}>
     */
    public function getEmergentPatternsList(): array
    {
        if (! is_array($this->emergent_patterns)) {
            return [];
        }
        $out = [];
        foreach ($this->emergent_patterns as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($row['name'] ?? ''),
                'confidence' => isset($row['confidence']) ? (float) $row['confidence'] : null,
                'surfaced_in_member_count' => isset($row['surfaced_in_member_count']) ? (int) $row['surfaced_in_member_count'] : null,
            ];
        }

        return $out;
    }
}
