<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Guild\LeadSources\Models\LeadSource as LeadSourceModel;

class LeadSourceManagementMutation
{
    public function create(mixed $root, array $request): LeadSourceModel
    {
        $data = $this->validate($request['input']);

        $leadSource = LeadSource::from($data);

        return (new CreateLeadSourceAction($leadSource))->execute();
    }

    public function update(mixed $root, array $request): LeadSourceModel
    {
        $input = $this->validate($request['input']);

        $leadSource = LeadSourceModel::getByUuidFromCompanyApp(
            $request['id'],
            company:CompaniesRepository::getByUuid($input['companies_id']),
            app: app(Apps::class)
        );
        $leadSource->update([
            'name' => $input['name'],
            'description' => $input['description'],
            'is_active' => $input['is_active'],
            'leads_types_id' => $input['leads_types_id'],
        ]);

        return $leadSource;
    }

    public function delete(mixed $root, array $request): bool
    {
        $leadSource = LeadSourceModel::getByUuidFromCompanyApp($request['id'], app: app(Apps::class));
        CompaniesRepository::userAssociatedToCompany($leadSource->company, auth()->user());

        return $leadSource->delete();
    }

    public function validate(array $input): array
    {
        $input['app'] = app(Apps::class);
        $input['company'] = CompaniesRepository::getByUuid($input['companies_id'], app: app(Apps::class), user: auth()->user());
        CompaniesRepository::userAssociatedToCompany($input['company'], auth()->user());
        $leadType = LeadType::getByUuidFromCompanyApp($input['leads_types_id'], company:$input['company'], app: app(Apps::class));
        $input['leads_types_id'] = $leadType->getId();

        return $input;
    }
}
