<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\AgentRuntime;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class AgentTelemetryQuery
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    public function __invoke(mixed $root, array $args): ?array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::where('id', $args['deployment_id'])
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->firstOrFail();

        return AgentRuntimeProviderFactory::forDeployment($deployment)->fetchTelemetrySnapshot($deployment);
    }
}
