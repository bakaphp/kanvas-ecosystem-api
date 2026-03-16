<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\OpenClaw\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Actions\DispatchAgentDeploymentAction;
use Kanvas\Connectors\OpenClaw\Actions\GetAgentContainerLogsAction;
use Kanvas\Connectors\OpenClaw\Actions\GetAgentContainerStatusAction;
use Kanvas\Connectors\OpenClaw\Jobs\RestartAgentContainerJob;
use Kanvas\Connectors\OpenClaw\Jobs\TerminateAgentJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

class AgentDeploymentMutation
{
    public function launch(mixed $root, array $request): AgentDeployment
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app);

        /** @var AgentMachine $machine */
        $machine = AgentMachine::getByIdFromCompanyApp((int) $input['machine_id'], $company, $app);

        return new DispatchAgentDeploymentAction(
            $agent,
            $machine,
            $app,
            $company,
        )->execute();
    }

    public function terminate(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        TerminateAgentJob::dispatch($deployment);

        return true;
    }

    public function restart(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        RestartAgentContainerJob::dispatch($deployment);

        return true;
    }

    public function logs(mixed $root, array $request): string
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $lines = (int) ($request['lines'] ?? 100);

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        return new GetAgentContainerLogsAction($deployment, $lines)->execute();
    }

    public function status(mixed $root, array $request): AgentDeployment
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        return new GetAgentContainerStatusAction($deployment)->execute();
    }
}
