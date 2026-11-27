<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

trait ResolvesImportableIntegration
{
    /**
     * A missing row, another app's row and a company the user is not in all answer the same, so the
     * response never says which integrations exist. Being an admin is not enough: the role spans the
     * app, and the row belongs to one company.
     */
    protected function importableIntegration(int $id): IntegrationsCompany
    {
        try {
            $integrationCompany = IntegrationsCompany::getManageable($id, app(Apps::class));
            CompaniesRepository::userMemberOfCompany($integrationCompany->company, auth()->user());
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException('Integration not found.');
        }

        return $integrationCompany;
    }
}
