<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\OpenClaw\Queries;

use Kanvas\Connectors\OpenClaw\SshClient;
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
        $deployment = AgentDeployment::with(['machine', 'agent'])->find($args['deployment_id']);

        if ($deployment === null || $deployment->machine === null || $deployment->agent === null) {
            return [];
        }

        $limit = min((int) ($args['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);

        $ssh  = SshClient::fromMachine($deployment->machine);
        $logs = $ssh->getDeploymentLogs($deployment->system_user, $deployment->agent->slug, $limit);
        $ssh->disconnect();

        return $logs;
    }
}
