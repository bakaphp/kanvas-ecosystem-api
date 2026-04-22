<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Reports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Reports\Repositories\InscriptionTrackRepository;

class EventInscriptionTrackQuery
{
    public function __invoke(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByIdFromCompanyApp((int) $args['event_version_id'], $company, $app);

        $excludeTypes = isset($args['exclude_types'])
            ? array_values(array_map('strval', (array) $args['exclude_types']))
            : [];

        return InscriptionTrackRepository::forEventVersion($eventVersion, $excludeTypes)
            ->map(fn ($row) => $row->toArray())
            ->all();
    }
}
