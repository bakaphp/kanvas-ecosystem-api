<?php

declare(strict_types=1);

namespace App\GraphQL\Analytics\Queries;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Analytics\Actions\BuildAnalyticsAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

class AgentAnalyticsQuery
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
            model: Agent::class,
            app: $app,
            company: $company,
            request: AnalyticsRequest::fromGraphQL($args, $company),
            groupBys: [
                'by_type' => new AnalyticsGroupBy(
                    column: 'agent_type_id',
                    relation: 'type',
                    labelColumn: 'name',
                ),
                'by_model' => new AnalyticsGroupBy(
                    column: 'agent_model_id',
                    relation: 'model',
                    labelColumn: 'name',
                ),
                'by_deployment_status' => new AnalyticsGroupBy(column: 'deployment_status'),
                'by_user' => new AnalyticsGroupBy(
                    column: 'user_id',
                    relation: 'user',
                    labelColumn: 'displayname',
                ),
            ],
            extraScopes: isset($args['agent_type_id'])
                ? fn (Builder $q) => $q->where('agent_type_id', (int) $args['agent_type_id'])
                : null,
        )->execute();
    }
}
