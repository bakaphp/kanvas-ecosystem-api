<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Observers\AgentObserver;
use Kanvas\Intelligence\Models\BaseModel;
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
    use SlugTrait;
    use UuidTrait;
    use HasFilesystemTrait;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use HasLightHouseCache;

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'agent_type_id',
        'parent_id',
        'path',
        'user_id',
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
    ];

    protected $casts = [
        'config' => Json::class,
        'role' => Json::class,
        'identity' => Json::class,
        'is_active' => 'boolean',
    ];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'AgentAi';
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

    public static function getModel(): Model
    {
        return new Agent();
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
            'users_id',
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
            'id' => $this->id,
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
