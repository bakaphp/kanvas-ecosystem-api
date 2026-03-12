<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Leads\Models\LeadSource as LeadSourceModel;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;

class LeadSourceManagementMutation
{
    public function create(mixed $root, array $request): LeadSourceModel
    {
        $data = $this->validate($request['input']);
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $leadSource = LeadSource::from($app, $company, $data);

        return new CreateLeadSourceAction($leadSource)->execute();
    }

    public function update(mixed $root, array $request): LeadSourceModel
    {
        $input = $this->validate($request['input']);
        $app = app(Apps::class);
        /** @var Companies $company */
        $company = $input['company'];

        $leadSource = LeadSourceModel::getByUuidFromCompanyApp(
            $request['id'],
            company: $company,
            app: $app
        );

        $dto = new LeadSource(
            app: $app,
            company: $company,
            leads_types_id: $input['leads_types_id'] ?? null,
            name: $input['name'],
            is_active: $input['is_active'],
            description: $input['description'] ?? null,
        );

        $leadSource->update([
            'name' => $dto->name,
            'description' => $dto->description,
            'is_active' => $dto->is_active,
            'leads_types_id' => $dto->leads_types_id,
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

        return $input;
    }
}
