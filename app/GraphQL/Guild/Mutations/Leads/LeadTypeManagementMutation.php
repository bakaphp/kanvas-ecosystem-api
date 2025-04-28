<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType;
use Kanvas\Guild\Leads\Models\LeadType as LeadTypeModel;

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

        return (new CreateLeadTypeAction($leadType))->execute();
    }

    public function update(mixed $root, array $req): LeadTypeModel
    {
        $app = app(Apps::class);
        $companies = CompaniesRepository::getByUuid($req['input']['companies_id'], $app, auth()->user());
        $req['input']['companies_id'] = $companies->getId();

        $leadType = LeadTypeModel::getByUuidFromCompanyApp($req['id'], app: app(Apps::class), company: $companies);
        $leadType->update($req['input']);

        return $leadType;
    }

    public function delete(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $leadType = LeadTypeModel::getByUuidFromCompanyApp($req['id'], app: app(Apps::class));
        $companies = CompaniesRepository::getByUuid($leadType->company->uuid, $app, auth()->user());

        return $leadType->delete();
    }
}
