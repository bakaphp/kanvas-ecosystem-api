<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Stats;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Reports\Repositories\TimeSlotOccupancyRepository;

class TimeSlotStatsQuery
{
    public function __invoke(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $input = $args['input'];
        $timezone = $input['timezone'] ?? 'UTC';

        $start = Carbon::parse((string) $input['startDate'], $timezone)->startOfDay()->timezone('UTC');
        $end = Carbon::parse((string) $input['endDate'], $timezone)->endOfDay()->timezone('UTC');

        $stats = TimeSlotOccupancyRepository::occupancy(
            app: $app,
            company: $company,
            start: $start,
            end: $end,
            timezone: $timezone,
            resourcesId: isset($input['resources_id']) ? (int) $input['resources_id'] : null,
            resourcesType: $input['resources_type'] ?? null,
        );

        return [
            'period_start' => $start->copy()->timezone($timezone)->format('Y-m-d H:i:s'),
            'period_end' => $end->copy()->timezone($timezone)->format('Y-m-d H:i:s'),
            ...$stats,
        ];
    }
}
