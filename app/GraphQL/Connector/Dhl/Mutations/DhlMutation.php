<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Dhl\Mutations;

use App\GraphQL\Workflow\Mutations\Integrations\IntegrationsMutation;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Models\Integrations;

class DhlMutation
{
    public function setup(mixed $root, array $request): bool
    {
        $input = $request['input'];
        $company = isset($input['company_id']) ? (int) $input['company_id'] : auth()->user()->getCurrentCompany()->getId();

        $integration = Integrations::query()
            ->where('name', IntegrationsEnum::DHL->value)
            ->where('apps_id', 0)
            ->firstOrFail();

        $integrationCompany = new IntegrationsMutation()->createIntegrationCompany($root, [
            'input' => [
                'integration' => ['id' => $integration->getId()],
                'company_id' => $company,
                'region' => ['id' => $input['region_id']],
                'config' => $input,
            ],
        ]);

        return $integrationCompany->status->slug === StatusEnum::ACTIVE->value;
    }
}
