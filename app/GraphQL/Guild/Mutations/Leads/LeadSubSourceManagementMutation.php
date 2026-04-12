<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\LeadSubSources\Actions\CreateLeadSubSourceAction;
use Kanvas\Guild\LeadSubSources\Actions\UpdateLeadSubSourceAction;
use Kanvas\Guild\LeadSubSources\DataTransferObject\LeadSubSource as LeadSubSourceData;
use Kanvas\Guild\LeadSubSources\Models\LeadSubSource;

class LeadSubSourceManagementMutation
{
    public function create(mixed $root, array $request): LeadSubSource
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $request['input'];

        $source = LeadSource::getByIdFromCompanyApp((int) $input['leads_sources_id'], $company, $app);

        return new CreateLeadSubSourceAction(
            new LeadSubSourceData(
                app: $app,
                company: $company,
                source: $source,
                name: $input['name'],
            ),
        )->execute();
    }

    public function update(mixed $root, array $request): LeadSubSource
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $request['input'];

        $subSource = LeadSubSource::getByIdFromCompanyApp((int) $request['id'], $company, $app);
        $source = LeadSource::getByIdFromCompanyApp((int) ($input['leads_sources_id'] ?? $subSource->leads_sources_id), $company, $app);

        return new UpdateLeadSubSourceAction(
            $subSource,
            new LeadSubSourceData(
                app: $app,
                company: $company,
                source: $source,
                name: $input['name'] ?? $subSource->name,
            ),
        )->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $subSource = LeadSubSource::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return $subSource->softDelete();
    }
}
