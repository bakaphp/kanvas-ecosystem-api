<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Observers\AgentSkillObserver;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Per-agent skill assignment.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agent_id
 * @property int $skill_id
 * @property int|null $granted_by_users_id
 * @property \Illuminate\Support\Carbon $granted_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property bool $is_active
 * @property array|null $config
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ObservedBy(AgentSkillObserver::class)]
class AgentSkill extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'nervous_system_agent_skills';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'agent_id' => 'integer',
            'skill_id' => 'integer',
            'granted_by_users_id' => 'integer',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'config' => Json::class,
            'is_deleted' => 'boolean',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'skill_id', 'id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'granted_by_users_id', 'id');
    }

    /**
     * Grant lifecycle events come from the user who granted/revoked.
     */
    protected function resolveDefaultActorType(): string
    {
        return $this->granted_by_users_id !== null ? 'User' : 'System';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->granted_by_users_id;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1)->where('is_deleted', 0);
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('is_active', 1);
    }
}
