<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Organizations\Actions\RecordOrganizationNoteAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesOrganizationForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\WritesNoteForEntity;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Writes a note into an account's notes thread — the same thread the Customer Update Agent reads back
 * to decide what an account cares about, so a note left here shapes the next monthly update.
 */
#[AgentTool(name: 'Add Organization Note', category: 'crm')]
class AddOrganizationNoteTool extends Tool implements HasRunKey
{
    use ResolvesOrganizationForTool;
    use TrackByInputs;
    use WritesNoteForEntity;

    public function __construct()
    {
        parent::__construct(
            name: 'add_organization_note',
            description: 'Write a note on a customer organization (company / account) so the team sees it in that '
                . 'account\'s notes thread. Use this to record what the account bought, what they use, what they '
                . 'asked for, or the outcome of a call — anything that belongs to the whole account rather than to '
                . 'one contact or one lead. Identify the account by organization_id when you have it, otherwise by '
                . 'organization_name. A note about one contact belongs on that person instead, and a note about a '
                . 'single opportunity belongs on that lead.',
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
                name: 'note',
                type: PropertyType::STRING,
                description: 'The note text, written for a human teammate to read.',
                required: true,
            ),
            new ToolProperty(
                name: 'organization_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the organization to write the note on. Preferred over the name.',
                required: false,
            ),
            new ToolProperty(
                name: 'organization_name',
                type: PropertyType::STRING,
                description: 'The organization name, when you do not have the id. Ambiguous names are refused with '
                    . 'a candidate list rather than guessed at.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $note, ?int $organization_id = null, ?string $organization_name = null): array
    {
        $note = trim($note);

        if ($note === '') {
            return $this->emptyNoteError();
        }

        $result = $this->resolveOrganization($organization_id, $organization_name);
        if (is_array($result)) {
            return $result;
        }
        $organization = $result;

        $recorded = new RecordOrganizationNoteAction($organization)->execute(
            $note,
            'agent-note',
            $this->contextUser(),
        );

        $response = $this->finalizeNote(
            $recorded,
            $organization,
            $note,
            'organization_id',
            'the account\'s notes thread',
        );

        // Confirms WHICH account matched when the model resolved it by name.
        $response['organization_name'] = $organization->name;

        return $response;
    }
}
