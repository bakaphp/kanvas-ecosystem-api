<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Workflow\Enums\WorkflowEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Soft-deletes a lead (not destroyed) and fires the DELETED workflow so downstream automations run.
 *
 * Unlike the deleteLead GraphQL mutation this leaves the lead's custom fields in place: an agent
 * deleting on a hunch must stay reversible by restoreLead, and dropping the fields makes it lossy.
 *
 * Admin-guarded where deal deletion is not, because a lead is the entry point of the prospect-facing
 * agents — a tool that a prospect can talk their way into calling must not be able to erase the
 * company's pipeline.
 */
#[AgentTool(name: 'Delete Lead', category: 'crm')]
class DeleteLeadTool extends Tool
{
    use GuardsAdminForTool;
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'delete_lead',
            description: 'Soft-delete a lead by its lead_id, removing it from the pipeline. The lead is recoverable '
                . 'by an admin afterwards. Use search_leads first to confirm you have the right lead, and confirm '
                . 'with the user before calling. If the lead is a duplicate of another one, use merge_lead instead — '
                . 'that keeps the history. Only do this when the user explicitly asks to delete/remove a lead. Only '
                . 'an administrator can do this.',
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
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead to delete.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return ['status' => 'error'] + $denied;
        }

        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $title = $lead->title;

        try {
            $lead->fireWorkflow(WorkflowEnum::DELETED->value, true);
            $lead->softDelete();
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => "Failed to delete lead {$lead_id}: {$e->getMessage()}",
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'message' => "Lead '{$title}' (lead_id {$lead_id}) was deleted.",
        ];
    }
}
