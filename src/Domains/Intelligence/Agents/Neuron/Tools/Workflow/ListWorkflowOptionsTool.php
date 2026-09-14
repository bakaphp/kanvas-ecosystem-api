<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Workflow;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesWorkflowCatalogForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The vocabulary create_company_workflow expects. Without it the model has to guess trigger, entity
 * and activity names out of hundreds of registered activities, and every guess is a failed create.
 */
#[AgentTool(name: 'List Workflow Options', category: 'workflow')]
class ListWorkflowOptionsTool extends Tool implements HasRunKey
{
    use ResolvesWorkflowCatalogForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_workflow_options',
            description: 'Lists the valid building blocks for create_company_workflow: triggers (what makes a '
                . 'workflow run), entities (which record type it watches), actions (the activities it can run) '
                . 'and receivers (the inbound endpoints this app can accept data on). Each action and receiver '
                . 'comes back with what it does, the params it reads, and whether the integration it needs is '
                . 'configured for this company — read those before choosing. Call this before '
                . 'create_company_workflow, and pass a search term when looking for something specific such as '
                . '"email", "slack" or "wordpress".',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'kind',
                type: PropertyType::STRING,
                description: 'Which catalog to list: "triggers", "entities", "actions", "receivers", or '
                    . '"all" (default).',
                required: false,
            ),
            new ToolProperty(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Optional term to filter entities, actions and receivers, e.g. "lead", "email", '
                    . '"slack", "wordpress". Matches names, descriptions and integration names.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $kind = null, ?string $search = null): array
    {
        $kind = mb_strtolower(trim((string) $kind)) ?: 'all';
        $search = Str::trimToNull((string) $search);

        $options = [];

        if ($kind === 'all' || $kind === 'triggers') {
            $options['triggers'] = $this->availableTriggers();
        }

        if ($kind === 'all' || $kind === 'entities') {
            $options['entities'] = $this->availableEntities($search);
        }

        if ($kind === 'all' || $kind === 'actions') {
            $options['actions'] = $this->searchActions($search);
        }

        if ($kind === 'all' || $kind === 'receivers') {
            $options['receivers'] = $this->searchReceivers($search);
        }

        if ($options === []) {
            return [
                'status' => 'error',
                'message' => sprintf(
                    '"%s" is not a catalog. Use triggers, entities, actions, receivers or all.',
                    $kind
                ),
            ];
        }

        return array_merge(['status' => 'success'], $options, [
            'note' => 'Use these names verbatim in create_company_workflow. An action whose "configured" is '
                . 'false cannot run yet — tell the admin which settings in "to_configure" to fill in rather '
                . 'than building the workflow around it and letting it fail silently. Receivers are inbound '
                . 'endpoints, not rule steps; never pass one as an action. The lists are capped — pass a '
                . 'search term to narrow them instead of assuming something does not exist.',
        ]);
    }
}
