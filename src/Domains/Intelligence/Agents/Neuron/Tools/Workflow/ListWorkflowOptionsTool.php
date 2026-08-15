<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Workflow;

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
                . 'workflow run), entities (which record type it watches) and actions (the activities it can run). '
                . 'Call this before create_company_workflow, and pass a search term when looking for a specific '
                . 'action such as "email" or "slack".',
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
                description: 'Which catalog to list: "triggers", "entities", "actions", or "all" (default).',
                required: false,
            ),
            new ToolProperty(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Optional term to filter entities and actions by name, e.g. "lead", "email", "slack".',
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
        $search = trim((string) $search) ?: null;

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

        if ($options === []) {
            return [
                'status' => 'error',
                'message' => sprintf('"%s" is not a catalog. Use triggers, entities, actions or all.', $kind),
            ];
        }

        return array_merge(['status' => 'success'], $options, [
            'note' => 'Use these names verbatim in create_company_workflow. The action list is capped — pass a '
                . 'search term to narrow it instead of assuming an action does not exist.',
        ]);
    }
}
