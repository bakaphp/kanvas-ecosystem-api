<?php

declare(strict_types=1);

namespace Kanvas\Guild\Agents\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Agents\Enums\AgentFilterEnum;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Class Agent.
 *
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 * @property string $users_linked_source_id
 * @property string $member_id
 * @property int $status_id
 * @property int $total_leads
 * @property int $owner_id
 * @property string $owner_linked_source_id
 */
class Agent extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'agents';
    protected $guarded = [];

    public function owner(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(
            Users::class,
            'owner_id',
            'id'
        );
    }

    /**
     * For the Enum in graph
     */
    public function status(): Attribute
    {
        return Attribute::make(
            get: fn () => [
                'id' => $this->status_id,
                'name' => $this->status_id,
            ]
        );
    }

    public function scopeFilterSettings(Builder $query, mixed $user = null): Builder
    {
        $user = $user instanceof UserInterface ? $user : auth()->user();

        if ($this->company->get(AgentFilterEnum::FITTER_BY_USER->value)) {
            return $query->where('users_id', $user->getId());
        }

        if ($this->company->get(AgentFilterEnum::FILTER_BY_BRANCH->value)) {
            return $query->where('companies_branches_id', $user->getCurrentBranch()->getId());
        }

        return $query;
    }
}
