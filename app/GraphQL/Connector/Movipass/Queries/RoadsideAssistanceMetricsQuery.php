<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\CalculateRoadsideAssistanceMetricsAction;

class RoadsideAssistanceMetricsQuery
{
    public function get(mixed $rootValue, array $request): array
    {
        $input = $request['input'] ?? [];

        return new CalculateRoadsideAssistanceMetricsAction(
            app: app(Apps::class),
            startDate: $input['startDate'] ?? null,
            endDate: $input['endDate'] ?? null,
            providerCompanyId: isset($input['provider_company_id']) ? (int) $input['provider_company_id'] : null,
            companyId: isset($input['company_id']) ? (int) $input['company_id'] : null,
        )->execute();
    }
}
