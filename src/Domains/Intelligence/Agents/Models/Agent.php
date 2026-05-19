<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
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
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Observers\AgentObserver;
use Kanvas\Intelligence\Models\BaseModel;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
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
 * @property string|null $output_format
 * @property array|null $identity
 * @property string|null $user_context
 * @property string|null $tools_config
 * @property string|null $deployment_status
 * @property int|null $agent_model_id
 * @property bool $is_active
 * @property bool $is_deleted
 */
#[ObservedBy(AgentObserver::class)]
class Agent extends BaseModel
{
    use AsTree;
    use CascadeSoftDeletes;
    use SlugTrait;
    use UuidTrait;
    use HasFilesystemTrait;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use HasLightHouseCache;

    protected $cascadeDeletes = ['deployments', 'swarmMemberships'];

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
        'output_format',
        'identity',
        'user_context',
        'tools_config',
        'deployment_status',
        'agent_model_id',
        'is_active',
        'awake_state',
        'last_state_changed_at',
    ];

    protected $casts = [
        'config' => Json::class,
        'role' => Json::class,
        'identity' => Json::class,
        'is_active' => 'boolean',
        'last_state_changed_at' => 'datetime',
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

    public function type(): BelongsTo
    {
        return $this->belongsTo(AgentType::class, 'agent_type_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AgentModel::class, 'agent_model_id');
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

    /**
     * Module subscriptions for this agent. Each row carries a per-agent JSON
     * `config` (e.g. which inventory integrations / channels / pipelines to
     * watch). Soft-deleted rows are excluded by default; `is_active=false`
     * rows are included so the UI can render disabled-but-configured state.
     */
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

        return $query;
    }
}
