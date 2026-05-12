<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\AgentRuntime\Queries;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class AgentTelemetryQuery
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    public function __invoke(mixed $root, array $args): ?array
    {
        $app     = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        AgentDeployment::where('id', $args['deployment_id'])
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->firstOrFail();

        /** @var array<string, mixed>|null */
        return Cache::get('openclaw:telemetry:' . $args['deployment_id']);
    }
}
