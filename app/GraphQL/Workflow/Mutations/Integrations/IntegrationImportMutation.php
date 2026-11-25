<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Integrations;

use App\GraphQL\Workflow\Concerns\ResolvesImportableIntegration;
use Kanvas\Workflow\Integrations\Imports\Actions\StartIntegrationImportAction;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;

class IntegrationImportMutation
{
    use ResolvesImportableIntegration;

    public function import(mixed $rootValue, array $request): IntegrationImport
    {
        return new StartIntegrationImportAction(
            $this->importableIntegration((int) $request['integration_company_id']),
            auth()->user(),
            $request['entity'],
            $request['options'] ?? [],
        )->execute();
    }
}
