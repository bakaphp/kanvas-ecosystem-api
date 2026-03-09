<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Intelligence\Models\BaseModel;

class AgentSwarm extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use DatabaseSearchableTrait {
        search as public traitSearch;
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
         ->withPivot('role', 'config')
         ->withTimestamps();
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
