<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Capability\Services\ActiveIntegrationsService;
use Kanvas\NervousSystem\Capability\Services\AgentTypeResolver;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * What kinds of teammate can be hired, before deciding to hire one.
 *
 * `hire_agent` built every hire on one hardcoded type, so an orchestrator asked for a developer
 * answered — correctly, for what it could actually produce — that the platform could not open a pull
 * request, while a `Claude Agent` and a `pi.dev Programming Agent` sat in the catalog unused. Naming
 * the type is only useful if the caller can first see which names exist and which of them this
 * company can actually run.
 */
#[AgentTool(name: 'List Agent Types', category: 'nervous_system')]
class ListAgentTypesTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_agent_types',
            description: 'List the kinds of agent you can hire — a plain conversational teammate, a coding '
                . 'agent that works in a sandbox and opens pull requests, a long-running one for multi-hour '
                . 'work, and the domain agents. Call this BEFORE hire_agent whenever the job is anything '
                . 'beyond reading and writing records, then pass the name you picked as hire_agent\'s '
                . 'agent_type. It also tells you what each type still needs from a human after hiring — a '
                . 'coding agent is not usable until an admin gives it a GitHub token and the repositories '
                . 'it may touch. Do not answer that the platform cannot do something technical without '
                . 'checking this first. Takes no arguments.',
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
                'agent_types' => [],
                'error' => 'This agent has no company context, so it cannot read the agent type catalog.',
            ];
        }

        $resolver = new AgentTypeResolver($this->app);
        $unavailable = $resolver->unavailableFor(new ActiveIntegrationsService($this->company)->names());

        $types = array_map(
            fn (array $type): array => $type + ['available' => ! in_array($type['name'], $unavailable, true)],
            $resolver->describe(),
        );

        return [
            'count' => count($types),
            'agent_types' => $types,
            'default' => $resolver->default()?->name,
            'note' => 'Pass the exact `name` as hire_agent\'s agent_type. A type marked available: false '
                . 'needs an integration this company has not connected — hiring onto it produces an agent '
                . 'that cannot run, so ask an admin to connect it instead. A type with requires_setup '
                . 'needs those things set on the agent by a human AFTER it is hired — you cannot set them '
                . 'yourself, and they are usually credentials, so say what is needed rather than working '
                . 'around it.',
        ];
    }
}
