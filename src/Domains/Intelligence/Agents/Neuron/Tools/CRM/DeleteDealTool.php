<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesDealForTool;
use Kanvas\Workflow\Enums\WorkflowEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Soft-deletes a deal (not destroyed) and fires the DELETED workflow so downstream automations run.
 */
#[AgentTool(name: 'Delete Deal', category: 'crm')]
class DeleteDealTool extends Tool
{
    use ResolvesDealForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'delete_deal',
            description: 'Soft-delete a deal by its deal_id, removing it from the pipeline. Use search_deals first to '
                . 'confirm you have the right deal. Only do this when the user explicitly asks to delete/remove a deal.',
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
                name: 'deal_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the deal to delete.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $deal_id): array
    {
        $result = $this->resolveDealOrError($deal_id);
        if (is_array($result)) {
            return $result;
        }
        $deal = $result;

        $title = $deal->title;

        try {
            $deal->fireWorkflow(WorkflowEnum::DELETED->value, true);
            $deal->softDelete();
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => "Failed to delete deal {$deal_id}: {$e->getMessage()}",
            ];
        }

        return [
            'status' => 'success',
            'deal_id' => $deal_id,
            'message' => "Deal '{$title}' (deal_id {$deal_id}) was deleted.",
        ];
    }
}
