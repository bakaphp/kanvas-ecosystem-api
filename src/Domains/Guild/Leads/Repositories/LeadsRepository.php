<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Repositories;

use Baka\Enums\StateEnums;
use Baka\Traits\SearchableTrait;
use Google\Collection;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
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

    public static function getPeopleActiveLeads(People $people): Builder
    {
        $mappingStatus = $people->company->get(ConfigurationEnum::MAPPING_STATUS_CRM->value);

        return Lead::fromApp($people->app)
                    ->fromCompany($people->company)
                    ->notDeleted()
                    ->where('people_id', $people->id)
                        ->whereHas('status', function ($query) use ($mappingStatus) {
                            if ($mappingStatus && is_array($mappingStatus) && key_exists('active', $mappingStatus) && is_array($mappingStatus['active'])) {
                                $query->whereIn('name', ...$mappingStatus['active']);
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

    public static function getPeopleClosedLead(People $people): ?Lead
    {
        return Lead::fromApp($people->app)
                    ->fromCompany($people->company)
                    ->notDeleted()
                    ->where('people_id', $people->id)
                        ->whereHas('status', function ($query) {
                            $query->whereNotIn('name', ['active', 'created']);
                        })
                        ->orderBy('id', 'desc')
                        ->first();
    }

    public static function getActiveLeadByCompany(Companies $company): ?Collection
    {
        $mappingStatus = $company->get(ConfigurationEnum::MAPPING_STATUS_CRM->value);

        return Lead::fromCompany($company)
                        ->notDeleted()
                        ->whereHas('status', function ($query) use ($mappingStatus) {
                            if ($mappingStatus && is_array($mappingStatus) && key_exists('active', $mappingStatus) && is_array($mappingStatus['active'])) {
                                $query->whereIn('name', ...$mappingStatus['active']);
                            } else {
                                $query->whereIn('name', ['active', 'created']);
                            }
                        })
                        ->get();
    }
}
