<?php

declare(strict_types=1);

namespace App\GraphQL\Analytics\Queries;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Analytics\Actions\BuildAnalyticsAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;

class IntegrationHistoryAnalyticsQuery
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
            model: EntityIntegrationHistory::class,
            app: $app,
            company: $company,
            request: AnalyticsRequest::fromGraphQL($args, $company),
            groupBys: [
                'by_integration' => new AnalyticsGroupBy(
                    column: 'integrations_id',
                    relation: 'integration',
                    labelColumn: 'name',
                ),
                'by_status' => new AnalyticsGroupBy(
                    column: 'status_id',
                    relation: 'status',
                    labelColumn: 'name',
                ),
                'by_entity_namespace' => new AnalyticsGroupBy(column: 'entity_namespace'),
            ],
            extraScopes: function (Builder $q) use ($args) {
                if (isset($args['integration_id'])) {
                    $q->where('integrations_id', (int) $args['integration_id']);
                }
                if (isset($args['status_id'])) {
                    $q->where('status_id', (int) $args['status_id']);
                }
                if (isset($args['entity_namespace'])) {
                    $q->where('entity_namespace', (string) $args['entity_namespace']);
                }
            },
        )->execute();
    }
}
