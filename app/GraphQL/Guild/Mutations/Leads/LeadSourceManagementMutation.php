<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\LeadSource as LeadSourceModel;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;

class LeadSourceManagementMutation
{
    public function create(mixed $root, array $request): LeadSourceModel
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $leadSource = LeadSource::from($app, $company, $request['input']);

        return new CreateLeadSourceAction($leadSource)->execute();
    }

    public function update(mixed $root, array $request): LeadSourceModel
    {
        $app = app(Apps::class);
        /** @var Companies $company */
        $input = $request['input'];
        $company = auth()->user()->getCurrentCompany();

        $leadSource = is_numeric($request['id'])
            ? LeadSourceModel::getByIdFromCompanyApp((int) $request['id'], $company, $app)
            : LeadSourceModel::getByUuidFromCompanyApp($request['id'], company: $company, app: $app);

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
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $leadSource = is_numeric($request['id'])
            ? LeadSourceModel::getByIdFromCompanyApp((int) $request['id'], $company, $app)
            : LeadSourceModel::getByUuidFromCompanyApp($request['id'], company: $company, app: $app);

        return $leadSource->delete();
    }
}
