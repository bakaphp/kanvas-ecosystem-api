<?php

declare(strict_types=1);

namespace App\GraphQL\Analytics\Queries;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Analytics\Actions\BuildAnalyticsAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;

class LeadAnalyticsQuery
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
            model: Lead::class,
            app: $app,
            company: $company,
            request: AnalyticsRequest::fromGraphQL($args, $company),
            groupBys: [
                'by_status' => new AnalyticsGroupBy(
                    column: 'leads_status_id',
                    relation: 'status',
                    labelColumn: 'name',
                ),
                'by_source' => new AnalyticsGroupBy(
                    column: 'leads_sources_id',
                    relation: 'source',
                    labelColumn: 'name',
                ),
                'by_pipeline' => new AnalyticsGroupBy(
                    column: 'pipeline_id',
                    relation: 'pipeline',
                    labelColumn: 'name',
                ),
                'by_user' => new AnalyticsGroupBy(
                    column: 'leads_owner_id',
                    relation: 'owner',
                    labelColumn: 'displayname',
                ),
            ],
            extraScopes: isset($args['pipeline_id'])
                ? fn (Builder $q) => $q->where('pipeline_id', (int) $args['pipeline_id'])
                : null,
        )->execute();
    }
}
