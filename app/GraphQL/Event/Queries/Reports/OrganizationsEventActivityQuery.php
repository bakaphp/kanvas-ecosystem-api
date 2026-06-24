<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Reports;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Reports\Enums\OrgActivityFilterEnum;
use Kanvas\Event\Reports\Enums\OrgActivityOrderEnum;
use Kanvas\Event\Reports\Repositories\OrganizationEventActivityRepository;

class OrganizationsEventActivityQuery
{
    public function __invoke(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $fromDate = isset($args['from_date']) ? Carbon::parse((string) $args['from_date']) : null;
        $toDate = isset($args['to_date']) ? Carbon::parse((string) $args['to_date']) : null;

        $activity = isset($args['activity'])
            ? OrgActivityFilterEnum::from((string) $args['activity'])
            : OrgActivityFilterEnum::ALL;

        $orderBy = isset($args['order_by'])
            ? OrgActivityOrderEnum::from((string) $args['order_by'])
            : OrgActivityOrderEnum::COUNT_DESC;

        $includeParticipantTypes = isset($args['include_participant_types'])
            ? array_values(array_map('strval', (array) $args['include_participant_types']))
            : null;
        $excludeParticipantTypes = isset($args['exclude_participant_types'])
            ? array_values(array_map('strval', (array) $args['exclude_participant_types']))
            : [];

        return OrganizationEventActivityRepository::query(
            app: $app,
            company: $company,
            fromDate: $fromDate,
            toDate: $toDate,
            activity: $activity,
            minCount: isset($args['min_count']) ? (int) $args['min_count'] : null,
            maxCount: isset($args['max_count']) ? (int) $args['max_count'] : null,
            eventTypeId: isset($args['event_type_id']) ? (int) $args['event_type_id'] : null,
            eventCategoryId: isset($args['event_category_id']) ? (int) $args['event_category_id'] : null,
            includeParticipantTypes: $includeParticipantTypes,
            excludeParticipantTypes: $excludeParticipantTypes,
            topN: isset($args['top_n']) ? (int) $args['top_n'] : null,
            orderBy: $orderBy,
        )
            ->map(fn ($row) => $row->toArray())
            ->all();
    }
}
