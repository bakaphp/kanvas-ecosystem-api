<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Leads;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\LeadStatus;

class LeadStatusManagementMutation
{
    public function create(mixed $root, array $request): LeadStatus
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $leadStatus = LeadStatus::create(array_merge($request['input'], [
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]));

        return $leadStatus;
    }

    public function update(mixed $root, array $request): LeadStatus
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $leadStatus = LeadStatus::where('id', $request['id'])
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->firstOrFail();

        $leadStatus->update($request['input']);

        return $leadStatus;
    }

    public function delete(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $leadStatus = LeadStatus::where('id', $request['id'])
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->firstOrFail();

        return $leadStatus->delete();
    }
}
