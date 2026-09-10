<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Repositories;

use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Traits\SearchableTrait;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Override;

class LeadsRepository
{
    use SearchableTrait;

    #[Override]
    public static function getModel(): Model
    {
        return new Lead();
    }

    /**
    * @psalm-suppress MixedReturnStatement
    */
    public static function getDefaultReceiver(CompaniesBranches $branch): LeadReceiver
    {
        return LeadReceiver::where('companies_branches_id', $branch->getId())
                    ->where('is_default', StateEnums::YES->getValue())
                    ->firstOrFail();
    }

    private static function leadsForPeople(People $people): Builder
    {
        return Lead::fromApp($people->app)
                    ->fromCompany($people->company)
                    ->notDeleted()
                    ->where('people_id', $people->id);
    }

    public static function getPeopleActiveLeads(People $people): Builder
    {
        $mappingStatus = $people->company->get(ConfigurationEnum::MAPPING_STATUS_CRM->value);

        return self::leadsForPeople($people)
                        ->whereHas('status', function ($query) use ($mappingStatus) {
                            if ($mappingStatus && is_array($mappingStatus) && key_exists('active', $mappingStatus) && is_array($mappingStatus['active'])) {
                                $query->whereIn('name', $mappingStatus['active']);
                            } else {
                                $query->whereIn('name', ['active', 'created']);
                            }
                        });
    }

    public static function getPeopleActiveLead(People $people): ?Lead
    {
        /** @psalm-suppress LessSpecificReturnStatement */
        return self::getPeopleActiveLeads($people)
                        ->orderBy('id', 'desc')
                        ->first();
    }

    public static function getPeopleLastLead(People $people): ?Lead
    {
        /** @psalm-suppress LessSpecificReturnStatement */
        return self::leadsForPeople($people)
                    ->orderBy('id', 'desc')
                    ->first();
    }

    /**
     * Careful: this excludes the *live* names, so it answers "not active", not
     * "terminal" — and it does not read MAPPING_STATUS_CRM the way its active
     * counterpart does. A status in neither list therefore satisfies both this
     * and getPeopleNonClosedLeads(): a Reynolds "Open" lead reads as closed here
     * and as live there. Left as-is because eLead's fallback and DriveCentric
     * depend on the current behaviour and neither path has coverage; fixing it
     * means deciding what "closed" means platform-wide, which is the `is_closed`
     * column the Lead model already has a TODO for.
     */
    public static function getPeopleClosedLeads(People $people): Builder
    {
        return self::leadsForPeople($people)
                        ->whereHas('status', fn ($query) => $query->whereNotIn('name', ['active', 'created']))
                        ->orderBy('id', 'desc');
    }

    public static function getPeopleClosedLead(People $people): ?Lead
    {
        /** @psalm-suppress LessSpecificReturnStatement */
        return self::getPeopleClosedLeads($people)->first();
    }

    /**
     * The statuses that mean a lead is done, for the callers that need to exclude
     * them rather than whitelist the live ones.
     *
     * @todo The default is known-incomplete and the override is unused. Of the
     * status names in leads_status only 'Closed', 'Sold' and 'Lost' are matched:
     * 'Close' (a separate row from 'Closed'), 'Won', 'Complete', 'Duplicate',
     * 'Bad' and 'Inactive' all fall through and read as live. No company has
     * MAPPING_STATUS_CRM set, so every tenant gets this default. Widening the
     * list is not the fix — the names are per-tenant and unbounded. The fix is
     * the `is_closed` column on leads_status that Lead's own TODO already calls
     * for, so the lifecycle is declared by the data instead of by string lists
     * in three places. Until then, treat "not closed" as "not obviously closed".
     *
     * @return array<int, string>
     */
    public static function closedStatusNames(CompanyInterface $company): array
    {
        $mappingStatus = $company->get(ConfigurationEnum::MAPPING_STATUS_CRM->value);

        if (is_array($mappingStatus)
            && isset($mappingStatus['closed'])
            && is_array($mappingStatus['closed'])
            && ! empty($mappingStatus['closed'])
        ) {
            return $mappingStatus['closed'];
        }

        return ['closed', 'sold', 'lost'];
    }

    /**
     * Leads that are not in a terminal status — the exclusion counterpart to
     * getPeopleActiveLeads().
     *
     * It exists for CRMs whose vocabulary we do not control: Reynolds dealers
     * publish prospects as "Open", which the active whitelist rejects, so a live
     * lead reads as closed to a whitelist.
     *
     * Exclusion is the lesser of two wrong answers here, not a correct one. It
     * errs toward showing a dead lead; the whitelist errs toward hiding a live
     * one. Neither is a definition of the lifecycle — see closedStatusNames()
     * for why, and for what would actually fix it.
     *
     * This is also why it is not the negation of getPeopleClosedLeads(): the two
     * exclude different lists, so a status in neither satisfies both.
     */
    public static function getPeopleNonClosedLeads(People $people): Builder
    {
        return self::leadsForPeople($people)
                        ->whereHas(
                            'status',
                            fn ($query) => $query->whereNotIn('name', self::closedStatusNames($people->company))
                        )
                        ->orderBy('id', 'desc');
    }
}
