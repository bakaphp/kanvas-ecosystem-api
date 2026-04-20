<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Reports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Reports\Repositories\OpenEventsTrackingRepository;

class EventsTrackingQuery
{
    public function __invoke(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $filters = [];
        $filters['weeks_ahead'] = isset($args['weeks_ahead']) ? (int) $args['weeks_ahead'] : 7;
        if (isset($args['event_type_id'])) {
            $filters['event_type_id'] = (int) $args['event_type_id'];
        }
        if (isset($args['event_class_id'])) {
            $filters['event_class_id'] = (int) $args['event_class_id'];
        }
        if (isset($args['event_category_id'])) {
            $filters['event_category_id'] = (int) $args['event_category_id'];
        }
        if (isset($args['search'])) {
            $filters['search'] = (string) $args['search'];
        }
        if (isset($args['color'])) {
            $filters['color'] = (string) $args['color'];
        }
        if (isset($args['has_goal'])) {
            $filters['has_goal'] = (bool) $args['has_goal'];
        }

        return OpenEventsTrackingRepository::forCompany($app, $company, $filters)
            ->map(fn ($row) => $row->toArray())
            ->all();
    }
}
