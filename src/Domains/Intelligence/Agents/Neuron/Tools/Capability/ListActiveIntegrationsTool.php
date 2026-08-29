<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Capability;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Capability\Services\ActiveIntegrationsService;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Which external services this company has actually connected.
 *
 * `capability_lookup` covers TOOLS, and can only say "owned but unconfigured here" for the four
 * connectors `ConnectorReadinessService` lists by name — so for every other integration "not set up"
 * and "does not exist" are the same answer, and an agent calls a live connector impossible.
 *
 * Reads the tenant's own switched-on list rather than a registry, so a connector added later needs no
 * change here.
 */
#[AgentTool(name: 'List Active Integrations', category: 'nervous_system')]
class ListActiveIntegrationsTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_active_integrations',
            description: 'List the external services this company has connected and switched on — the '
                . 'CRMs, coding agents, publishing targets and data providers it can actually reach. Use '
                . 'it before telling anyone that something cannot be integrated, reached or automated: '
                . 'an integration missing from this list is not set up HERE, which is a different answer '
                . 'from "the platform cannot do it" and needs a different reply. Takes no arguments.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        if (! $this->hasTenantContext()) {
            return [
                'count' => 0,
                'integrations' => [],
                'error' => 'This agent has no company context, so it cannot read the integration list.',
            ];
        }

        $integrations = new ActiveIntegrationsService($this->company)->describe();

        return [
            'count' => count($integrations),
            'integrations' => $integrations,
            'note' => $integrations === []
                ? 'This company has connected nothing yet. Anything needing an external service has to '
                    . 'be set up by an admin before an agent can use it.'
                : 'These are connected for THIS company. Something absent here may still exist on the '
                    . 'platform — check capability_lookup before calling it impossible.',
        ];
    }
}
