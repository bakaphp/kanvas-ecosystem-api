<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Queries;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideQuoteCalculator;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Exceptions\ValidationException;

class RoadsideQuoteQuery
{
    public function get(mixed $rootValue, array $request): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        if (! RegionalAssistance::isEnabled($app, $company)) {
            throw new ValidationException('Roadside quotes are only available for Asistencia Regional');
        }

        $input = $request['input'];

        $moment = isset($input['requested_at']) ? Carbon::parse($input['requested_at']) : Carbon::now();

        return new RoadsideQuoteCalculator(RegionalAssistance::tariffs($app, $company, $moment))->quote(
            service: RoadsideServiceTypeEnum::from((string) $input['service_type']),
            location: $input['location'],
            destination: $input['destination'] ?? null,
            moment: $moment,
        )->toArray();
    }
}
