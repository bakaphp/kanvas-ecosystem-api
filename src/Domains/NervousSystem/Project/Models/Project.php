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
use Kanvas\AdminLinks\Enums\AdminLinkSectionEnum;
use Kanvas\AdminLinks\Traits\HasAdminLink;
use Kanvas\Intelligence\Agents\Contracts\HandlesAgentMention;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Observers\ProjectObserver;
use Kanvas\NervousSystem\Project\Services\ProjectMentionTriggerService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Nevadskiy\Tree\AsTree;
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
 * @property string|null $path
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
class Project extends BaseModel implements HandlesAgentMention
{
    use HasAdminLink;
    use AsTree;
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
    public function adminLinkSection(): AdminLinkSectionEnum
    {
        return AdminLinkSectionEnum::AGENT_PROJECT;
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

    #[Override]
    public function handleAgentMention(Agent $agent, Message $mention, int $threadRootId): ?object
    {
        if ($this->is_deleted || $this->agent_id !== $agent->getId()) {
            return null;
        }

        return new WakeAgentForProjectJob(
            $this,
            WakeAgentForProjectJob::REASON_MENTION,
            new ProjectMentionTriggerService()->buildTrigger($mention),
            $threadRootId,
        );
    }

    public function getParentKeyName(): string
    {
        return 'parent_project_id';
    }

    public function children(): HasMany
    {
        return $this->hasMany(
            self::class,
            $this->getParentKeyName(),
            'id'
        )->where('is_deleted', 0);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(
            Plan::class,
            'project_id',
            'id'
        )->where('is_deleted', 0);
    }

    public function members(): HasMany
    {
        return $this->hasMany(
            ProjectMember::class,
            'project_id',
            'id'
        )->where('is_deleted', 0);
    }

    public function defaultChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'default_channel_id', 'id');
    }

    public function receiverWebhook(): BelongsTo
    {
        return $this->belongsTo(ReceiverWebhook::class, 'receiver_webhook_id', 'id');
    }

    /**
     * The project's inbound ingest endpoint — external providers POST context here (§4.3). Null until
     * the webhook is provisioned.
     */
    public function getWebhookUrlAttribute(): ?string
    {
        $webhook = $this->receiverWebhook;
        if ($webhook === null) {
            return null;
        }

        // The receiver route lives under the app's API prefix (e.g. "v1"), so it must be part of the
        // URL — without it the endpoint 404s. Derive it from config so it can't drift from the route.
        $base = rtrim((string) config('app.url'), '/');
        $prefix = trim((string) config('kanvas.application.routes.prefix'), '/');
        $path = ($prefix !== '' ? "/{$prefix}" : '') . '/receiver/';

        return $base . $path . (string) $webhook->uuid;
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
     * A Project is an agent-conversation entity (the PM is woken with the Project in scope), and the
     * chat/context path calls getName() on the entity as it does for a Lead/People. Expose the title.
     */
    public function getName(): string
    {
        return $this->title ?? '';
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

    public function isOverdue(): bool
    {
        return ! in_array($this->status, ProjectStatusEnum::terminalStatusValues(), true)
            && $this->deadline_at !== null
            && $this->deadline_at->isPast()
            && $this->completion_pct < 100;
    }

    public function isAtRisk(): bool
    {
        return $this->isOverdue() || $this->status === ProjectStatusEnum::BLOCKED->value;
    }

    /**
     * Roll the project's completion up from its (non-deleted) plans — the average of their
     * completion_pct — and persist it. Returns the new percentage.
     */
    public function recomputeCompletionPct(): int
    {
        $plans = $this->plans()->get();

        $pct = $plans->isEmpty() ? 0 : (int) round((float) $plans->avg('completion_pct'));

        $this->completion_pct = $pct;
        $this->saveOrFail();

        return $pct;
    }
}
