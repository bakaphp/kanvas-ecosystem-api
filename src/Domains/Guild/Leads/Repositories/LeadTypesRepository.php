<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Repositories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\LeadType;

class LeadTypesRepository
{
    public function getByUuid(string $uuid, Companies $companies, ?Apps $app = null)
    {
        $app = $app ?? app(Apps::class);
        LeadType::where('uuid', $uuid)
                ->where('companies_id', $companies->getId())
                ->where('apps_id', $app->getId())
                ->first();
    }
}
