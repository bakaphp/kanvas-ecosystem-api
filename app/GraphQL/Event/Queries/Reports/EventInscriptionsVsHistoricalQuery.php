<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Reports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Reports\Repositories\InscriptionsVsHistoricalRepository;

class EventInscriptionsVsHistoricalQuery
{
    public function __invoke(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByIdFromCompanyApp((int) $args['event_version_id'], $company, $app);

        $includeTypes = isset($args['include_types'])
            ? array_values(array_map('strval', (array) $args['include_types']))
            : null;
        $excludeTypes = isset($args['exclude_types'])
            ? array_values(array_map('strval', (array) $args['exclude_types']))
            : [];

        return InscriptionsVsHistoricalRepository::forEventVersion(
            $eventVersion,
            cumulative: (bool) ($args['cumulative'] ?? false),
            includeTypes: $includeTypes,
            excludeTypes: $excludeTypes,
        )->toArray();
    }
}
