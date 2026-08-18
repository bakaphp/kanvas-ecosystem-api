<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithUser;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Intelligence\Agents\Observers\AgentObserver;
use Kanvas\Intelligence\Agents\Types\OpenClawAgentHandler;
use Kanvas\Intelligence\Models\BaseModel;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Nevadskiy\Tree\AsTree;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agent_type_id
 * @property int|null $parent_id
 * @property string|null $path
 * @property int $user_id
 * @property int|null $created_by_users_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property array|null $config
 * @property int|null $company_task_list_id
 * @property array|null $role
 * @property string|null $soul
 * @property string|null $instructions
 * @property string|null $tool_usage
 * @property string|null $output_format
 * @property array|null $identity
 * @property string|null $user_context
 * @property string|null $tools_config
 * @property array|null $voice_config
 * @property string|null $deployment_status
 * @property int|null $agent_model_id
 * @property int|null $agent_llm_config_id
 * @property bool $is_active
 * @property bool $is_deleted
 */
#[ObservedBy(AgentObserver::class)]
class Agent extends BaseModel
{
    use AsTree;
    use CanUseWorkflow;
    use CascadeSoftDeletes;
    use SlugTrait;
    use UuidTrait;
    use HasFilesystemTrait;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use HasLightHouseCache;

    /** What an agent is TOLD, as opposed to what it can touch. Edits here are snapshotted first. */
    public const array PROMPT_FIELDS = [
        'soul',
        'instructions',
        'identity',
        'user_context',
        'output_format',
        'role',
    ];

    // Set before saving; AgentObserver reads them. Plain properties, so Eloquent never persists them.
    public ?string $versionChangeReason = null;
    public ?int $versionEditedByUserId = null;
    public ?AgentVersion $lastRecordedVersion = null;

    protected $cascadeDeletes = [
        'deployments',
        'swarmMemberships',
        'scheduledActions',
    ];

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'agent_type_id',
        'parent_id',
        'path',
        'user_id',
        'created_by_users_id',
        'name',
        'slug',
        'description',
        'config',
        'company_task_list_id',
        'role',
        'soul',
        'instructions',
        'tool_usage',
        'output_format',
        'identity',
        'user_context',
        'tools_config',
        'voice_config',
        'deployment_status',
        'agent_model_id',
        'agent_llm_config_id',
        'is_active',
        'is_sub_agent',
        'awake_state',
        'last_state_changed_at',
    ];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'AgentAi';
    }

    #[Override]
    public function getRelations(?string $modelClass = null): array
    {
        return func_num_args() > 0 ? [] : $this->relations;
    }

    #[Override]
    public function casts(): array
    {
        return [
            'config' => Json::class,
            'role' => Json::class,
            'identity' => Json::class,
            'voice_config' => Json::class,
            'is_active' => 'boolean',
            'is_sub_agent' => 'boolean',
            'last_state_changed_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AgentType::class, 'agent_type_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AgentModel::class, 'agent_model_id');
    }

    public function llmConfig(): BelongsTo
    {
        return $this->belongsTo(AgentLlmConfig::class, 'agent_llm_config_id');
    }

    public function companyTaskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'company_task_list_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AgentHistory::class);
    }

    public function communicationChannels(): BelongsToMany
    {
        return $this->belongsToMany(CommunicationChannel::class, 'agent_communication_channels')
            ->withPivot('entry_point', 'config')
            ->withTimestamps();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class);
    }

    public function performanceMetrics(): HasMany
    {
        return $this->hasMany(AgentPerformanceMetric::class);
    }

    public function swarms(): BelongsToMany
    {
        return $this->belongsToMany(
            AgentSwarm::class,
            'agent_swarm_members',
            'agent_id',
            'agent_swarm_id'
        )->wherePivot('is_deleted', 0)
         ->withPivot('role', 'config')
         ->withTimestamps();
    }

    public function swarmMemberships(): HasMany
    {
        return $this->hasMany(AgentSwarmMember::class, 'agent_id');
    }

    public function selectedTools(): BelongsToMany
    {
        return $this->belongsToMany(
            Tool::class,
            'nervous_system_agent_selected_tools',
            'agent_id',
            'tool_id'
        );
    }

    public function kanvasModules(): HasMany
    {
        return $this->hasMany(AgentKanvasModule::class, 'agent_id', 'id')
            ->where('agents_kanvas_modules.is_deleted', 0);
    }

    public function activeKanvasModules(): HasMany
    {
        return $this->hasMany(AgentKanvasModule::class, 'agent_id', 'id')
            ->where('agents_kanvas_modules.is_deleted', 0)
            ->where('agents_kanvas_modules.is_active', 1);
    }

    public function dailyCycles(): HasMany
    {
        return $this->hasMany(AgentDailyCycle::class, 'agent_id', 'id')
            ->where('agent_daily_cycles.is_deleted', 0)
            ->orderBy('cycle_date', 'desc');
    }

    public function scheduledActions(): HasMany
    {
        return $this->hasMany(ScheduledAction::class, 'agent_id', 'id')
            ->where('nervous_system_scheduled_actions.is_deleted', 0);
    }

    public function latestDailyCycle(): HasOne
    {
        return $this->hasOne(AgentDailyCycle::class, 'agent_id', 'id')
            ->where('agent_daily_cycles.is_deleted', 0)
            ->latestOfMany('cycle_date');
    }

    public static function getModel(): Model
    {
        return new Agent();
    }

    /**
     * Find an agent by ID scoped to the given company/app.
     * Falls back to a global agent (companies_id = 0) or app-global (apps_id = 0) if not found.
     */
    public static function getByIdWithGlobalFallback(int $id, Apps $app, mixed $company): self
    {
        $companyId = is_int($company) ? $company : $company->getId();

        $agent = self::where('id', $id)
            ->notDeleted()
            ->where(function ($q) use ($app, $companyId) {
                $q->where(function ($q) use ($app, $companyId) {
                    $q->where('apps_id', $app->getId())
                        ->where('companies_id', $companyId);
                })->orWhere(function ($q) use ($app) {
                    $q->where('apps_id', $app->getId())
                        ->where('companies_id', 0);
                })->orWhere(function ($q) use ($companyId) {
                    $q->where('apps_id', 0)
                        ->where('companies_id', $companyId);
                })->orWhere(function ($q) {
                    $q->where('apps_id', 0)
                        ->where('companies_id', 0);
                });
            })
            ->orderByRaw('(apps_id = 0) ASC, (companies_id = 0) ASC')
            ->first();

        if (! $agent) {
            throw new ModelNotFoundException(
                sprintf('No Agent record found with ID %s for this app/company or globally', $id)
            );
        }

        return $agent;
    }

    #[Override]
    protected static function newFactory()
    {
        return new AgentFactory();
    }

    #[Override]
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            Users::class,
            'user_id',
            'id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            Users::class,
            'created_by_users_id',
            'id'
        );
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(AgentDeployment::class);
    }

    public function activeDeployment(): HasOne
    {
        return $this->hasOne(AgentDeployment::class)
            ->where('status', 'running')
            ->where('is_deleted', 0)
            ->latestOfMany();
    }

    public function isContainerRuntime(): bool
    {
        if ($this->activeDeployment instanceof AgentDeployment) {
            return true;
        }

        $provider = AgentProviderEnum::tryFrom(strtolower($this->type?->provider ?? ''));

        if ($provider?->isRuntimeProvider() === true) {
            return true;
        }

        return $this->type?->handler === OpenClawAgentHandler::class;
    }

    /**
     * Can this agent execute Nervous System board work (own a plan, create/move tasks)? Only in-process
     * Neuron agents (BaseKanvasAgent) can — they're the ones the kernel injects the board toolset into.
     * Container/ADK agents run remotely (no local tools), and other in-process types (e.g. CRMAgent)
     * assume a Lead/People entity and fatal when handed a Plan/Task. Use this to keep those out of the
     * executor role instead of assigning work they can't do (or that crashes them).
     */
    public function canExecuteBoardWork(): bool
    {
        $handler = $this->type?->handler;

        if (! is_string($handler) || $handler === '') {
            return false;
        }

        // A hosted runtime (Claude Managed Agents) executes our PHP tools through the custom-tool
        // bridge, so it CAN hold the board toolset — the test is capability, not transport. Machine
        // runtimes (Hermes/OpenClaw) stay excluded: they run their own kanban and cannot hold our
        // tools at all.
        if ($this->isHostedRuntime()) {
            return true;
        }

        return ! $this->isContainerRuntime()
            && is_a($handler, BehavesAsKanvasAgent::class, true);
    }

    /**
     * Vendor-hosted runtime — the loop and sandbox live on the vendor's infrastructure, so there is
     * no deployment row to consult; the agent type's provider is the whole answer.
     */
    public function isHostedRuntime(): bool
    {
        return AgentProviderEnum::tryFrom(strtolower($this->type?->provider ?? ''))?->isHosted() === true;
    }

    /**
     * Whether this agent talks to a user privately — its handler implements
     * ConversesWithUser. An internal system agent whose conversation stays on the
     * user↔agent channel and never posts into a customer-facing lead timeline.
     */
    public function conversesWithUser(): bool
    {
        $handler = $this->type?->handler;

        return $handler !== null
            && $handler !== ''
            && class_exists($handler)
            && is_subclass_of($handler, ConversesWithUser::class);
    }

    /**
     * Whether this agent is customer-facing — its handler implements ConversesWithCustomer.
     * The mirror of conversesWithUser: an external agent speaks to a prospect as a persona and
     * must stay prospect-isolated (no company-wide ledger recall on the customer surface).
     */
    public function conversesWithCustomer(): bool
    {
        $handler = $this->type?->handler;

        return $handler !== null
            && $handler !== ''
            && class_exists($handler)
            && is_subclass_of($handler, ConversesWithCustomer::class);
    }

    /**
     * The agent whose identity is this user, within a tenant — how any user-targeted
     * signal (assignment, @mention, ...) asks "is this teammate actually an agent?".
     * Company-scoped, so it never resolves an agent from another tenant.
     */
    public static function fromUser(
        int $userId,
        AppInterface $app,
        CompanyInterface $company
    ): ?self {
        /** @var self|null $agent */
        $agent = self::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('user_id', $userId)
            ->first();

        return $agent;
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_agent_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'agents');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'deployment_status' => $this->deployment_status,
            'is_active' => $this->is_active,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'apps_id', 'type' => 'int64'],
                ['name' => 'companies_id', 'type' => 'int64'],
                ['name' => 'name', 'type' => 'string', 'optional' => true],
                ['name' => 'slug', 'type' => 'string', 'optional' => true],
                ['name' => 'description', 'type' => 'string', 'optional' => true],
                ['name' => 'deployment_status', 'type' => 'string', 'optional' => true],
                ['name' => 'is_active', 'type' => 'bool', 'optional' => true],
            ],
        ];
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

        if ($query->model->isTypesense()) {
            $query->options([
                'query_by' => 'name,slug,description',
            ]);
        }

        return $query;
    }
}
