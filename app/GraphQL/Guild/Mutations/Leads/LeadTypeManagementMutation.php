<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType;
use Kanvas\Guild\Leads\Models\LeadType as LeadTypeModel;
use Kanvas\Guild\Leads\Repositories\LeadTypesRepository;

class LeadTypeManagementMutation
{
    /**
     * Create a new lead type.
     */
    public function create(mixed $root, array $req): LeadTypeModel
    {
        $data = $req['input'];
        $data['apps'] = app(Apps::class);
        $data['companies'] = CompaniesRepository::getByUuid($data['companies_id']);

        $leadType = LeadType::from($data);

        return (new CreateLeadTypeAction($leadType))->create();
    }

    public function update(mixed $root, array $req): LeadTypeModel
    {
        $app = app(Apps::class);
        $companies = CompaniesRepository::getByUuid($req['input']['companies_id'], $app);

        $lead = LeadTypesRepository::getByUuid($req['id'], $companies);

    }
}
