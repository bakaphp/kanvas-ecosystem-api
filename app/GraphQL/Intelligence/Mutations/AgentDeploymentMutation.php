<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

/**
 * Provider-agnostic agent deployment mutations.
 *
 * Routes by `agent_deployments.provider` (terminate) or the `provider` input field (launch),
 * dispatching to the correct provider-specific job via AgentProviderEnum. This is the canonical
 * entry point — clients no longer need to pick `openclawLaunchAgent` vs `hermesLaunchAgent` etc.
 * (The per-provider mutations remain for backwards compatibility.)
 */
class AgentDeploymentMutation
{
    public function launch(mixed $root, array $args): AgentDeployment
    {
        $app = app(Apps::class);

        /** @var array<string, mixed> $input */
        $input = $args['input'];

        /** @var Agent $agent */
        $agent = Agent::getById((int) $input['agent_id'], $app);
        $company = $agent->company;

        /** @var AgentMachine $machine */
        $machine = AgentMachine::getByIdFromCompanyApp(
            (int) $input['machine_id'],
            $company,
            $app,
        );

        $provider = AgentProviderEnum::from(strtolower((string) ($input['provider'] ?? 'openclaw')));

        return $provider->dispatchDeployment($agent, $machine, $app, $company)->execute();
    }

    public function terminate(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::getByIdFromCompanyApp((int) $request['deployment_id'], $company, $app);

        try {
            $provider = AgentProviderEnum::from((string) $deployment->provider);
        } catch (Throwable) {
            throw new ValidationException(
                'Cannot terminate deployment — unknown provider: ' . (string) ($deployment->provider ?? '<null>')
            );
        }

        $provider->dispatchTermination($deployment);

        return true;
    }
}
