<?php

declare(strict_types=1);

namespace Kanvas\Guild\Agents\Models;

use Baka\Contracts\CompanyInterface;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Guild\Agents\Enums\AgentFilterEnum;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Class Agent.
 *
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property int|null $apps_id
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
    protected $table = 'agents';
    protected $guarded = [];

    /**
     * @psalm-suppress MixedReturnStatement
     * @psalm-suppress MixedPropertyFetch
     */
    public function owner(): Users
    {
        try {
            return self::setConnection('crm')
                ->isActive()
                ->where('users_linked_source_id', $this->owner_linked_source_id)
                ->where('companies_id', $this->companies_id)
                ->firstOrFail()->user;
        } catch (ModelNotFoundException $e) {
            return self::setConnection('crm')
                ->isActive()
                ->where('member_id', $this->owner_id)
                ->where('companies_id', $this->companies_id)
                ->firstOrFail()->user;
        }
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

    public static function getByMemberNumber(string|int $memberNumber, CompanyInterface $company): self
    {
        return self::where('member_id', $memberNumber)
            ->fromCompany($company)
            ->firstOrFail();
    }

    public static function getNextAgentNumber(CompanyInterface $company): int
    {
        $maxMemberId = Agent::where('companies_id', $company->getId())
                            ->max('member_id');

        return $maxMemberId + 1;
    }

    public function scopeFilterSettings(Builder $query, mixed $user = null): Builder
    {
        $user = $user instanceof UserInterface ? $user : auth()->user();
        $company = $user->getCurrentCompany();

        $userAgent = Agent::where('users_id', $user->getId())->where('companies_id', $company->getId())->first();
        $query->where('users_id', '>', 0);

        $lookingForSpecificUser = $query->wheresContain('users_id', '=', $user->getId());

        if ($company->get(AgentFilterEnum::FITTER_BY_USER->value) && ! $lookingForSpecificUser) {
            $memberId = $user->get('member_number_' . $company->getId()) ? $user->get('member_number_' . $company->getId()) : $user->getId();

            return $query->where('owner_id', $memberId);
        } elseif ($company->get(AgentFilterEnum::FITTER_BY_OWNER->value) && ! $lookingForSpecificUser) {
            return $query->where('owner_linked_source_id', $userAgent->users_linked_source_id);
        }

        if ($company->get(AgentFilterEnum::FILTER_BY_BRANCH->value)) {
            return $query->where('companies_id', $company->getId());
        }

        return $query;
    }

    public function scopeIsActive(Builder $query): Builder
    {
        return $query->where('status_id', 1);
    }

    public function getMemberNumber(): string
    {
        /**
         * @psalm-suppress RedundantCastGivenDocblockType
         */
        return (string) $this->member_id;
    }
}
