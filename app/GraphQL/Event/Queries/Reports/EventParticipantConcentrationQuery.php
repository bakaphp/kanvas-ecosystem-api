<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Reports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Reports\Repositories\ParticipantConcentrationRepository;

class EventParticipantConcentrationQuery
{
    public function __invoke(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByIdFromCompanyApp((int) $args['event_version_id'], $company, $app);

        $topN = isset($args['top_n']) ? (int) $args['top_n'] : null;
        $includeTypes = isset($args['include_types'])
            ? array_values(array_map('strval', (array) $args['include_types']))
            : null;
        $excludeTypes = isset($args['exclude_types'])
            ? array_values(array_map('strval', (array) $args['exclude_types']))
            : [];

        return ParticipantConcentrationRepository::forEventVersion(
            $eventVersion,
            topN: $topN,
            includeTypes: $includeTypes,
            excludeTypes: $excludeTypes,
        )
            ->map(fn ($row) => $row->toArray())
            ->all();
    }
}
