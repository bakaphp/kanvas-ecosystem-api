<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Guild\LeadSources\Models\LeadSource as LeadSourceModel;

class LeadStatusManagementMutation
{
    public function create(mixed $root, array $req): LeadSourceModel
    {
        $app = app(Apps::class);
        $company = CompaniesRepository::getByUuid($req['input']['companies_id'], $app);
        $req['input']['app'] = $app;
        $req['input']['company'] = $company;
        $dto = LeadSource::from($req['input']);

        return (new CreateLeadSourceAction($dto))->execute();

    }
}
