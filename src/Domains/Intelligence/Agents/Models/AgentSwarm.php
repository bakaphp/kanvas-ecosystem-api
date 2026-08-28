<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Traits\HasAdminLink;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Intelligence\Agents\Observers\AgentSwarmObserver;
use Kanvas\Intelligence\Models\BaseModel;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\SystemModules\Models\SystemModules;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $status
 * @property array|null $config
 * @property bool $is_active
 * @property bool $is_deleted
 */
#[ObservedBy([AgentSwarmObserver::class])]
class AgentSwarm extends BaseModel
{
    use HasAdminLink;
    use CascadeSoftDeletes;
    use HasLightHouseCache;
    use UuidTrait;
    use SlugTrait;
    use DatabaseSearchableTrait {
        search as public traitSearch;
    }

    /**
     * Cascade soft-deletes everything that's exclusively owned by this swarm.
     * `agents` is intentionally absent — agents exist independently and may
     * belong to other swarms or operate standalone; only the membership
     * pivot rows (the `members` relation) get soft-deleted alongside the
     * swarm.
     *
     * @var array<int, string>
     */
    protected $cascadeDeletes = [
        'members',
        'dailyCycles',
        'budgets',
        'swarmPlans',
    ];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'AgentSwarm';
    }

    #[Override]
    public function adminLinkSection(): AdminLinkSectionEnum
    {
        return AdminLinkSectionEnum::AGENT_SWARM;
    }

    /**
     * Required because Social\Channels\Models\Channel stores `entity_id`
     * as varchar — Eloquent won't cast int → string for the FK pairing.
     */
    public function getStringIdAttribute(): string
    {
        return (string) $this->id;
    }

    public function socialChannels(): HasMany
    {
        return $this->hasMany(Channel::class, 'entity_id', 'string_id')
            ->whereIn(
                'entity_namespace',
                [
                    self::class,
                    SystemModules::getLegacyNamespace(self::class),
                ],
            )
            ->where('is_deleted', 0);
    }

    protected $table = 'agent_swarms';

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'users_id',
        'name',
        'slug',
        'description',
        'status',
        'config',
        'is_active',
    ];

    protected $casts = [
        'config' => Json::class,
        'is_active' => 'boolean',
    ];

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(
            Agent::class,
            'agent_swarm_members',
            'agent_swarm_id',
            'agent_id'
        )->wherePivot('is_deleted', 0)
         ->withPivot('role', 'config', 'parent_id')
         ->withTimestamps();
    }

    public function members(): HasMany
    {
        return $this->hasMany(AgentSwarmMember::class, 'agent_swarm_id')
            ->where('is_deleted', 0);
    }

    public function dailyCycles(): HasMany
    {
        return $this->hasMany(AgentSwarmDailyCycle::class, 'agent_swarm_id')
            ->where('is_deleted', 0);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(AgentSwarmBudget::class, 'agent_swarm_id')
            ->where('is_deleted', 0);
    }

    /**
     * Plans tied to this swarm (mission plans + any child plans referencing
     * this swarm_id). Named `swarmPlans` (not `plans`) to avoid colliding
     * with the parent's existing relation graph if it ever adds a generic
     * `plans` accessor.
     */
    public function swarmPlans(): HasMany
    {
        return $this->hasMany(Plan::class, 'swarm_id', 'id')
            ->where('is_deleted', 0);
    }

    /**
     * The plan currently flagged as this swarm's active mission. Filters
     * out terminal-status plans so a completed mission falls off the
     * swarm card automatically — operator must explicitly assign a new
     * mission for the card to repopulate. Historical missions stay
     * `is_swarm_mission=true` for swarm timeline reads.
     *
     * Demote-on-write inside Create/UpdatePlanAction guarantees at most
     * one row per swarm satisfies (is_swarm_mission=1 AND non-terminal),
     * so HasOne is correct without a `latestOfMany`. We deliberately do
     * NOT use `latestOfMany()` because it picks the latest row by PK
     * BEFORE applying the WHEREs — when a swarm has newer non-mission
     * plans (drafts, sub-tasks), latestOfMany would pick those, the
     * filters would all fail, and the relation would return null even
     * though a valid mission exists. orderByDesc('id') is defensive
     * against an impossible "multiple matches" state.
     */
    public function activeMission(): HasOne
    {
        return $this->hasOne(Plan::class, 'swarm_id', 'id')
            ->where('is_swarm_mission', 1)
            ->where('is_deleted', 0)
            ->whereNotIn('status', array_map(
                fn (PlanStatusEnum $s) => $s->value,
                PlanStatusEnum::terminalStatuses(),
            ))
            ->orderByDesc('id');
    }

    public function getAgentCountAttribute(): int
    {
        return $this->agents()->count();
    }

    public function getDeploymentStatusAttribute(): string
    {
        /** @var Collection<int, Agent> $agents */
        $agents = $this->agents()->get();

        if ($agents->isEmpty()) {
            return 'pending';
        }

        $statuses = $agents->pluck('deployment_status')->unique();

        if ($statuses->count() === 1 && $statuses->first() === 'deployed') {
            return 'deployed';
        }

        if ($statuses->contains('deployed')) {
            return 'partial';
        }

        return 'configuring';
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
