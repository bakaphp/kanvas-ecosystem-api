<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Writes a note into the lead's activity thread — the channel the team actually reads. This is the
 * only agent-facing path that produces a visible note: update_lead's qualification fields and the
 * lead Description box are separate surfaces.
 */
#[AgentTool(name: 'Add Lead Note', category: 'crm')]
class AddLeadNoteTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesLeadForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'add_lead_note',
            description: 'Write a note on a lead so the team sees it in the lead\'s activity thread. '
                . 'Use this whenever you are asked to "leave a note", "log this on the lead", or to record '
                . 'why something was done (why a lead was closed, what a customer asked for, what you did). '
                . 'Use search_leads to get the lead_id first. '
                . 'This writes to the activity thread — use update_lead_description for the lead\'s main '
                . 'Description box, and set_lead_status to actually change the lead\'s status.',
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
                description: 'The ID of the lead to write the note on (from search_leads or get_lead_ref).',
                required: true,
            ),
            new ToolProperty(
                name: 'note',
                type: PropertyType::STRING,
                description: 'The note text, written for a human teammate to read.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id, string $note): array
    {
        $note = trim($note);

        if ($note === '') {
            return [
                'status' => 'error',
                'message' => 'The note is empty — write what you actually want recorded before calling this tool.',
            ];
        }

        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $recorded = new RecordLeadNoteAction($lead)->execute(
            $note,
            'agent-note',
            $this->contextUser(),
        );

        if ($recorded === null) {
            return [
                'status' => 'error',
                'lead_id' => $lead->getId(),
                'message' => sprintf(
                    'The note could not be saved on lead %d. Do not claim it was saved — tell the user it failed.',
                    $lead->getId(),
                ),
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead->getId(),
            'note' => $note,
            'message' => 'Note saved on the lead\'s activity thread.',
        ];
    }
}
