<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Hermes\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Hermes\Actions\DispatchAgentDeploymentAction;
use Kanvas\Connectors\Hermes\Jobs\MigrateFromOpenClawJob;
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

    public function migrateFromOpenclaw(mixed $root, array $request): AgentDeployment
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $request['input'];

        /** @var AgentDeployment $sourceDeployment */
        $sourceDeployment = AgentDeployment::getByIdFromCompanyApp(
            (int) $input['source_deployment_id'],
            $company,
            $app
        );

        /** @var AgentMachine $destinationMachine */
        $destinationMachine = AgentMachine::getByIdFromCompanyApp(
            (int) $input['destination_machine_id'],
            $company,
            $app
        );

        MigrateFromOpenClawJob::dispatch(
            $sourceDeployment,
            $destinationMachine,
            $app,
            $company,
            $input['source_path'] ?? null,
            $input['destination_path'] ?? null,
        );

        return $sourceDeployment;
    }
}
