<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType;
use Kanvas\Guild\Leads\Models\LeadType as LeadTypeModel;

class LeadTypeManagementMutation
{
    public function create(mixed $root, array $req): LeadTypeModel
    {
        $data = $req['input'];
        $data['apps'] = app(Apps::class);
        $data['companies'] = auth()->user()->getCurrentCompany();

        $leadType = LeadType::from($data);

        return new CreateLeadTypeAction($leadType)->execute();
    }

    public function update(mixed $root, array $req): LeadTypeModel
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $req['input'];
        unset($input['companies_id']);

        $leadType = is_numeric($req['id'])
            ? LeadTypeModel::getByIdFromCompanyApp((int) $req['id'], $company, $app)
            : LeadTypeModel::getByUuidFromCompanyApp($req['id'], company: $company, app: $app);
        $leadType->update($input);

        return $leadType;
    }

    public function delete(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $leadType = is_numeric($req['id'])
            ? LeadTypeModel::getByIdFromCompanyApp((int) $req['id'], $company, $app)
            : LeadTypeModel::getByUuidFromCompanyApp($req['id'], company: $company, app: $app);

        return $leadType->delete();
    }
}
