<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Repositories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\LeadType;

class LeadTypesRepository
{
    public static function getByUuid(string $uuid, ?Companies $companies = null, ?Apps $app = null): LeadType
    {
        $app = $app ?? app(Apps::class);

        return LeadType::where('uuid', $uuid)
                ->when($companies, function ($query, $companies) {
                    $query->where('companies_id', $companies->getId());
                })
                ->where('apps_id', $app->getId())
                ->firstOrFail();
    }
}
