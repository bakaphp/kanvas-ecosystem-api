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
        $activeStatuses = self::mappedStatusNames($people->company, 'active', ['active', 'created']);

        return self::leadsForPeople($people)
                        ->whereHas('status', fn ($query) => $query->whereIn('name', $activeStatuses));
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
     * "Not active", not "terminal": a status in neither list (Reynolds' "Open") satisfies both
     * this and getPeopleNonClosedLeads().
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
     * @todo The default misses terminal names like 'Won', 'Duplicate' and 'Inactive', and they
     * are per-tenant, so widening the list won't fix it — the `is_closed` column on
     * leads_status that Lead's TODO calls for will.
     *
     * @return array<int, string>
     */
    public static function closedStatusNames(CompanyInterface $company): array
    {
        return self::mappedStatusNames($company, 'closed', ['closed', 'sold', 'lost']);
    }

    /**
     * Excludes closed names instead of whitelisting active ones, for CRMs whose vocabulary we
     * don't control: Reynolds publishes live prospects as "Open", which the whitelist hides.
     */
    public static function getPeopleNonClosedLeads(People $people): Builder
    {
        $closedStatuses = self::closedStatusNames($people->company);

        return self::leadsForPeople($people)
                        ->whereHas('status', fn ($query) => $query->whereNotIn('name', $closedStatuses))
                        ->orderBy('id', 'desc');
    }

    /**
     * @param array<int, string> $default
     *
     * @return array<int, string>
     */
    private static function mappedStatusNames(CompanyInterface $company, string $key, array $default): array
    {
        $mappingStatus = $company->get(ConfigurationEnum::MAPPING_STATUS_CRM->value);
        $mapped = is_array($mappingStatus) ? ($mappingStatus[$key] ?? null) : null;

        return is_array($mapped) && $mapped !== [] ? $mapped : $default;
    }
}
