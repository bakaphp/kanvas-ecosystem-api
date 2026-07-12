<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\AgentRuntime;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Live container heartbeat — SSHes into the deployment's machine (via the resolved
 * provider) and runs `docker compose ps` to check the container's actual state,
 * independent of whatever Kanvas last recorded.
 *
 * This exists because status changes made *outside* Kanvas (an operator manually
 * restarting a crashed container over SSH) never reach the admin UI otherwise:
 * `AgentDeploymentStatusChanged` only broadcasts on Kanvas-initiated transitions
 * (launch/restart/terminate/migrate), so a container that comes back on its own
 * leaves the DB row — and every open admin tab — stuck on the stale status until
 * something asks the machine directly. The frontend polls this on an interval
 * while a deployment's status view is open, same pattern as agentCurrentTelemetry.
 *
 * 30s cache matches the existing agentRuntimeContainerStatus mutation so a poll
 * and a manual refresh click within the same window share one SSH round trip.
 */
class AgentDeploymentContainerStatusQuery
{
    public function __invoke(mixed $root, array $args): AgentDeployment
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $args['deployment_id'], $company, $app);

        return Cache::remember(
            'agent-runtime:container-status:' . $deployment->id,
            30,
            fn (): AgentDeployment => AgentRuntimeProviderFactory::forDeployment($deployment)->fetchContainerStatus($deployment),
        );
    }
}
