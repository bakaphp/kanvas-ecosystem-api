<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\WritesNoteForEntity;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Add Person Note', category: 'crm')]
class AddPersonNoteTool extends Tool implements HasRunKey
{
    use ResolvesPersonForTool;
    use TrackByInputs;
    use WritesNoteForEntity;

    public function __construct()
    {
        parent::__construct(
            name: 'add_person_note',
            description: 'Write a note on a contact (person) so the team sees it in that contact\'s notes thread. '
                . 'Use this to record what a contact asked for, what you did for them, or why something changed — '
                . 'anything that belongs on the person rather than on a single lead or deal. '
                . 'Use find_person to get the person_id first. '
                . 'A note about one opportunity belongs on that lead instead, and a note about the whole '
                . 'account belongs on the organization.',
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
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the contact to write the note on (from find_person).',
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
    public function __invoke(int $person_id, string $note): array
    {
        $note = trim($note);

        if ($note === '') {
            return $this->emptyNoteError();
        }

        $result = $this->resolvePersonOrError($person_id);
        if (is_array($result)) {
            return $result;
        }
        $person = $result;

        $recorded = new RecordPeopleNoteAction($person)->execute(
            $note,
            'agent-note',
            $this->contextUser(),
        );

        return $this->finalizeNote(
            $recorded,
            $person,
            $note,
            'person_id',
            'the contact\'s notes thread',
        );
    }
}
