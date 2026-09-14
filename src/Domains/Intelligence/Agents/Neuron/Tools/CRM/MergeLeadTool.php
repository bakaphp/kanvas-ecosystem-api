<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Actions\MergeLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Collapses a duplicate lead into a surviving one — moves deals, attempts, participants,
 * engagements, campaign recipients and follow-up history onto the target, then soft-deletes the
 * source with a pointer back to the survivor. Irreversible; confirm both ids with the user first.
 */
#[AgentTool(name: 'Merge Lead', category: 'crm')]
class MergeLeadTool extends Tool
{
    use GuardsAdminForTool;
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'merge_lead',
            description: 'Merge a duplicate lead into another. source_lead_id is the duplicate (soft-deleted after), '
                . 'target_lead_id is the survivor that keeps everything. Deals, attempts, participants, engagements, '
                . 'campaign recipients, follow-up history and custom fields all move to the target, and any contact '
                . 'detail the target is missing (email, phone, name) is copied over from the source. This is '
                . 'irreversible — use search_leads or lead_ref to verify the two leads really are the same prospect '
                . 'and confirm the ids with the user before calling. Only an administrator can do this.',
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
                name: 'source_lead_id',
                type: PropertyType::INTEGER,
                description: 'The duplicate lead id to merge FROM (soft-deleted after the merge).',
                required: true,
            ),
            new ToolProperty(
                name: 'target_lead_id',
                type: PropertyType::INTEGER,
                description: 'The surviving lead id to merge INTO (keeps all data).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $source_lead_id, int $target_lead_id): array
    {
        if ($denied = $this->requireAdminOrError()) {
            return ['status' => 'error'] + $denied;
        }

        if ($source_lead_id === $target_lead_id) {
            return [
                'status' => 'error',
                'message' => 'source_lead_id and target_lead_id must be different.',
            ];
        }

        $source = $this->resolveLeadOrError($source_lead_id);
        if (is_array($source)) {
            return $source;
        }

        $target = $this->resolveLeadOrError($target_lead_id);
        if (is_array($target)) {
            return $target;
        }

        try {
            $survivor = new MergeLeadAction($source, $target, $this->contextUser())->execute();
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => "Failed to merge lead {$source_lead_id} into {$target_lead_id}: {$e->getMessage()}",
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $survivor->getId(),
            'title' => $survivor->title,
            'merged_from' => $source_lead_id,
            'message' => "Lead {$source_lead_id} was merged into lead {$target_lead_id} ('{$survivor->title}').",
        ];
    }
}
