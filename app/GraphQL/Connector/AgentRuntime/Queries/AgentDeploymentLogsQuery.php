<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\AgentRuntime\Queries;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class AgentDeploymentLogsQuery
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT     = 500;

    /**
     * @param  array<string, mixed>  $args
     * @return array<int, array{ts:string,level:string,msg:string,meta:string|null}>
     */
    public function __invoke(mixed $root, array $args): array
    {
        $app     = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $limit   = min((int) ($args['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);

        $deployment = AgentDeployment::with(['machine', 'agent'])
            ->where('id', $args['deployment_id'])
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->firstOrFail();

        if ($deployment->machine === null || $deployment->agent === null) {
            return [];
        }

        $cacheKey = 'openclaw:deployment-logs:' . $deployment->id . ':' . $limit;

        return Cache::remember($cacheKey, 30, function () use ($deployment, $limit) {
            $ssh  = SshClient::fromMachine($deployment->machine);
            $logs = $ssh->getDeploymentLogs($deployment->container_name, $deployment->agent->slug, $limit);
            $ssh->disconnect();

            return $logs;
        });
    }
}
