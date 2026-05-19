<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * Per-fleet spend / token / task budget. One active row per (swarm, period).
 * `hard_stop_at_cap` toggles whether members get paused (awake_state=
 * 'paused_budget') when any cap is hit.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agent_swarm_id
 * @property string $period
 * @property string|null $monthly_cost_cap_usd
 * @property int|null $monthly_token_cap
 * @property int|null $monthly_task_cap
 * @property bool $hard_stop_at_cap
 * @property int $warn_at_pct
 * @property int $period_resets_on
 * @property int|null $last_warned_at_pct
 * @property bool $is_active
 * @property bool $is_deleted
 */
class AgentSwarmBudget extends BaseModel
{
    use UuidTrait;

    protected $table = 'agent_swarm_budgets';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'agent_swarm_id' => 'integer',
            'monthly_cost_cap_usd' => 'string',
            'monthly_token_cap' => 'integer',
            'monthly_task_cap' => 'integer',
            'hard_stop_at_cap' => 'boolean',
            'warn_at_pct' => 'integer',
            'period_resets_on' => 'integer',
            'last_warned_at_pct' => 'integer',
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function swarm(): BelongsTo
    {
        return $this->belongsTo(AgentSwarm::class, 'agent_swarm_id', 'id');
    }
}
