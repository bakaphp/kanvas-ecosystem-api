<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberTypeEnum;
use Override;

/**
 * A member of a project — a human user OR an agent, in one pivot. An agent is already backed by a
 * real Users row (Agent.user_id), so every member is fundamentally "a user on the project"; the
 * agent_id just marks which of those users is an autonomous agent (and lets the heartbeat resolve
 * the Agent to wake).
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $project_id
 * @property string $member_type
 * @property int $users_id
 * @property int|null $agent_id
 * @property string $role
 * @property array|null $config
 * @property bool $is_active
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProjectMember extends BaseModel
{
    protected $table = 'nervous_system_project_members';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'project_id' => 'integer',
            'users_id' => 'integer',
            'agent_id' => 'integer',
            'config' => Json::class,
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    // user() (→ users_id) comes from KanvasModelTrait — don't redefine (concrete trait method).

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function scopeUsers(Builder $query): Builder
    {
        return $query->where('member_type', ProjectMemberTypeEnum::USER->value);
    }

    public function scopeAgents(Builder $query): Builder
    {
        return $query->where('member_type', ProjectMemberTypeEnum::AGENT->value);
    }
}
