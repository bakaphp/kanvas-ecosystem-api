<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Providers\Summary;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\KanvasModules\Contracts\KanvasModuleSummaryProvider;
use Override;

class CrmModuleSummaryProvider implements KanvasModuleSummaryProvider
{
    #[Override]
    public function summary(Companies $company, Apps $app): array
    {
        $leads = Lead::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->count();

        $people = People::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->count();

        return [
            'leads' => $leads,
            'people' => $people,
        ];
    }
}
