<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\OpenClaw\Queries;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentDeploymentEvent;

class AgentDeploymentEventsQuery
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    /**
     * @param  array<string, mixed>  $args
     * @return Collection<int, AgentDeploymentEvent>
     */
    public function __invoke(mixed $root, array $args): Collection
    {
        $app     = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $limit   = min((int) ($args['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);

        $deployment = AgentDeployment::where('id', $args['deployment_id'])
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->firstOrFail();

        return AgentDeploymentEvent::where('deployment_id', $deployment->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
