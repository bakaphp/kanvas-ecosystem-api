<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Models;

use Baka\Casts\Json;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Observers\ProjectObserver;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Users\Models\Users;
use Override;

/**
 * A Jira-style work container: the aggregate root that ties an objective, its members (humans and
 * agents), its plans/tasks and a unified message feed together, so an agent has full situational
 * awareness before it executes. Every project has a default PM agent (agent_id) that orchestrates it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int|null $workspace_id
 * @property int $agent_id
 * @property int|null $swarm_id
 * @property int|null $parent_project_id
 * @property string $title
 * @property string $slug
 * @property string|null $objective
 * @property string|null $description
 * @property string $status
 * @property int $priority
 * @property \Illuminate\Support\Carbon|null $deadline_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int $completion_pct
 * @property int $heartbeat_interval_minutes
 * @property \Illuminate\Support\Carbon|null $last_heartbeat_at
 * @property \Illuminate\Support\Carbon|null $next_heartbeat_at
 * @property int|null $default_channel_id
 * @property int|null $receiver_webhook_id
 * @property array|null $config
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ObservedBy([ProjectObserver::class])]
class Project extends BaseModel
{
    use CascadeSoftDeletes;
    use EmitsLedgerEventsForEntity;
    use HasLightHouseCache;
    use HasTagsTrait;
    use UuidTrait;

    /** Heartbeat cadences a project may pick (minutes); the scheduler ticks it when its interval elapses. */
    public const array ALLOWED_HEARTBEAT_INTERVALS = [5, 10, 15, 20, 30];

    protected $table = 'nervous_system_projects';

    protected $cascadeDeletes = ['plans', 'children', 'members'];

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'NervousSystemProject';
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'users_id' => 'integer',
            'workspace_id' => 'integer',
            'agent_id' => 'integer',
            'swarm_id' => 'integer',
            'parent_project_id' => 'integer',
            'priority' => 'integer',
            'completion_pct' => 'integer',
            'heartbeat_interval_minutes' => 'integer',
            'default_channel_id' => 'integer',
            'receiver_webhook_id' => 'integer',
            'config' => Json::class,
            'metadata' => Json::class,
            'is_deleted' => 'boolean',
            'deadline_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'next_heartbeat_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id', 'id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function pmAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function swarm(): BelongsTo
    {
        return $this->belongsTo(AgentSwarm::class, 'swarm_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_project_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_project_id', 'id')->where('is_deleted', 0);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class, 'project_id', 'id')->where('is_deleted', 0);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class, 'project_id', 'id')->where('is_deleted', 0);
    }

    public function defaultChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'default_channel_id', 'id');
    }

    /**
     * All Social channels bound to this project (the unified message feed) — polymorphic on the
     * varchar entity_id, the same pattern Lead::notes() / Plan::socialChannels() use. Project is a
     * new entity, so it only ever uses its own FQCN namespace (no legacy SystemModules alias).
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'entity_id', 'string_id')
            ->where('entity_namespace', self::class)
            ->where('is_deleted', 0);
    }

    /**
     * Channel::entity_id is varchar, so Eloquent won't cast int → string for the FK pairing.
     */
    public function getStringIdAttribute(): string
    {
        return (string) $this->id;
    }

    /**
     * A project is always orchestrated by its PM agent, so its lifecycle events are the agent's
     * work — attribute them to the Agent.
     */
    protected function resolveDefaultActorType(): string
    {
        return 'Agent';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->agent_id;
    }
}
