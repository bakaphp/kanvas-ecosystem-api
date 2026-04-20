<?php

declare(strict_types=1);

namespace App\GraphQL\Analytics\Queries;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Analytics\Actions\BuildAnalyticsAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class OrderAnalyticsQuery
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
            model: Order::class,
            app: $app,
            company: $company,
            request: AnalyticsRequest::fromGraphQL($args, $company),
            sumColumn: 'total_gross_amount',
            groupBys: [
                'by_status' => new AnalyticsGroupBy(column: 'status'),
                'by_payment_status' => new AnalyticsGroupBy(column: 'payment_status'),
                'by_fulfillment_status' => new AnalyticsGroupBy(column: 'fulfillment_status'),
            ],
            extraScopes: isset($args['status'])
                ? fn (Builder $q) => $q->where('status', (string) $args['status'])
                : null,
        )->execute();
    }
}
