<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Activities;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Actions\CopyDefaultAgentMachineAction;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentRuntimeSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\AgentRuntime\Services\AgentChannelIntegrationReadinessService;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;
use ValueError;

class ProvisionDefaultAgentRuntimeActivity extends KanvasActivity implements WorkflowActivityInterface
{
    private const string DEFAULT_CHANNEL = 'Web Chat';
    private const string DEFAULT_LANGUAGE_MODEL = 'Gemini';

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $this->resolveCompany($params);
        $defaultMachineId = (int) ($app->get(AgentRuntimeSettingEnum::DEFAULT_MACHINE_ID->value) ?? 0);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: fn (): array => $this->provision(
                $entity,
                $app,
                $company,
                $params,
                $defaultMachineId
            ),
            additionalParams: $params,
            company: $company,
        );
    }

    private function provision(
        Model $entity,
        AppInterface $app,
        CompanyInterface $company,
        array $params,
        int $defaultMachineId
    ): array {
        if (! $this->shouldRunForWelcomeState($entity, $params)) {
            return $this->failWorkflow(['msg' => 'welcome was not completed']);
        }

        if ($defaultMachineId <= 0) {
            return $this->failWorkflow(['msg' => 'no default machine configured']) ;
        }

        if (! $company instanceof CompanyInterface) {
            return $this->failWorkflow(['msg' => 'no company found for agent runtime provisioning']);
        }

        try {
            $sourceMachine = AgentMachine::query()
                ->fromApp($app)
                ->notDeleted()
                ->where('id', $defaultMachineId)
                ->first();

            if (! $sourceMachine instanceof AgentMachine) {
                return ['msg' => 'default machine not found', 'machine_id' => $defaultMachineId];
            }

            if ((int) $sourceMachine->companies_id === $company->getId()) {
                return ['msg' => 'default machine already belongs to company', 'machine_id' => $sourceMachine->getId()];
            }

            $machine = new CopyDefaultAgentMachineAction($sourceMachine, $company, $app)->execute();
            $provider = AgentRuntimeProviderFactory::forProvider($this->resolveProvider($params));
            $readiness = new AgentChannelIntegrationReadinessService();

            $agentIds = [];
            $deployedAgentIds = [];
            $deploymentIds = [];

            $agents = Agent::query()
                ->fromApp($app)
                ->fromCompany($company)
                ->notDeleted()
                ->where('is_active', 1)
                ->get();

            foreach ($agents as $agent) {
                if (! $agent instanceof Agent) {
                    continue;
                }

                $agentIds[] = $agent->getId();

                $isReady = $readiness->isReady($agent);

                // Every provisioned agent gets the default Web Chat + Gemini
                // config; runtime only turns on for agents that can deploy.
                $config = is_array($agent->config) ? $agent->config : [];
                $config['channel'] = self::DEFAULT_CHANNEL;
                $config['language_model'] = self::DEFAULT_LANGUAGE_MODEL;
                $config['runtime'] = true;
                $agent->config = $config;
                $agent->saveOrFail();

                if (! $isReady) {
                    continue;
                }

                $deployment = $provider->dispatchDeployment(
                    $agent,
                    $machine,
                    $app,
                    $company
                );
                $deployedAgentIds[] = $agent->getId();
                $deploymentIds[] = $deployment->getId();
            }

            return [
                'machine_id' => $machine->getId(),
                'agent_ids' => $agentIds,
                'deployed_agent_ids' => $deployedAgentIds,
                'deployment_ids' => $deploymentIds,
                'provider' => $provider->name()->value,
            ];
        } catch (Throwable $e) {
            return $this->failWorkflow([
                'error' => $e->getMessage(),
                'trace' => $e->getTrace(),
            ]);
        }
    }

    private function resolveCompany(array $params): ?CompanyInterface
    {
        $company = $params['company'] ?? null;

        if ($company instanceof CompanyInterface) {
            return $company;
        }

        if (is_numeric($company)) {
            return Companies::getById((int) $company);
        }

        return null;
    }

    private function shouldRunForWelcomeState(Model $entity, array $params): bool
    {
        if (array_key_exists('welcome_changed', $params)) {
            return (bool) $params['welcome_changed']
                && (int) ($params['welcome_previous'] ?? 1) === 0
                && (int) ($params['welcome_current'] ?? 0) === 1;
        }

        if ($entity instanceof Users && $entity->wasChanged('welcome')) {
            return (int) $entity->getOriginal('welcome') === 0
                && (int) $entity->welcome === 1;
        }

        return false;
    }

    private function resolveProvider(array $params): AgentProviderEnum
    {
        $provider = strtolower((string) ($params['provider'] ?? AgentProviderEnum::HERMES->value));

        try {
            $provider = AgentProviderEnum::from($provider);

            return in_array($provider, AgentProviderEnum::runtimeProviders(), true)
                ? $provider
                : AgentProviderEnum::HERMES;
        } catch (ValueError) {
            return AgentProviderEnum::HERMES;
        }
    }
}
