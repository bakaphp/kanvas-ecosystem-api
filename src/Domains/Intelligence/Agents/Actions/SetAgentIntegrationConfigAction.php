<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Connectors\Hermes\Jobs\DeployAgentIntegrationConfigJob;
use Kanvas\Intelligence\Agents\Enums\AgentIntegrationConfigKeyEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\WorkflowEnum;

class SetAgentIntegrationConfigAction
{
    /**
     * @param array<int, array{key: AgentIntegrationConfigKeyEnum, value: mixed}> $entries
     */
    public function __construct(
        protected Agent $agent,
        protected array $entries,
    ) {
    }

    public function execute(): Agent
    {
        $changedKeys = [];

        foreach ($this->entries as $entry) {
            $key = $entry['key']->value;
            $this->agent->set($key, $entry['value'] ?? null);
            $changedKeys[] = $key;
        }

        $this->agent->fireWorkflow(
            WorkflowEnum::AFTER_CONFIGURATION->value,
            true,
            [
                'app' => $this->agent->app,
                'company' => $this->agent->company,
                'integration_config_changed' => true,
                'changed_keys' => $changedKeys,
            ],
        );

        // Push the new credentials onto the agent's running runtime container.
        DeployAgentIntegrationConfigJob::dispatch(
            $this->agent,
            $changedKeys
        );

        return $this->agent;
    }
}
