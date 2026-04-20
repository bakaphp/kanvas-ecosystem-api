<?php

declare(strict_types=1);

namespace App\GraphQL\Analytics\Queries;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Analytics\Actions\BuildAnalyticsAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Users\Models\Users;

class EventAnalyticsQuery
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $rootValue, array $args): array
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = app()->bound(CompaniesBranches::class)
            ? app(CompaniesBranches::class)->company
            : $user->getCurrentCompany();

        return new BuildAnalyticsAction(
            model: Event::class,
            app: $app,
            company: $company,
            request: AnalyticsRequest::fromGraphQL($args, $company),
            groupBys: [
                'by_status' => new AnalyticsGroupBy(
                    column: 'event_status_id',
                    relation: 'eventStatus',
                    labelColumn: 'name',
                ),
                'by_type' => new AnalyticsGroupBy(
                    column: 'event_type_id',
                    relation: 'eventType',
                    labelColumn: 'name',
                ),
                'by_category' => new AnalyticsGroupBy(
                    column: 'event_category_id',
                    relation: 'eventCategory',
                    labelColumn: 'name',
                ),
                'by_class' => new AnalyticsGroupBy(
                    column: 'event_class_id',
                    relation: 'eventClass',
                    labelColumn: 'name',
                ),
            ],
            extraScopes: isset($args['event_type_id'])
                ? fn (Builder $q) => $q->where('event_type_id', (int) $args['event_type_id'])
                : null,
        )->execute();
    }
}
