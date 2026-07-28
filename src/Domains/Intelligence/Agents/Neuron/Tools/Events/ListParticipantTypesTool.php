<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Events;

use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\Tool;
use Override;

/**
 * The participant-type catalog (names + ids). Used to resolve a type name the user mentions into the
 * include/exclude filters the event reports accept. Company-scoped.
 */
#[AgentTool(name: 'List Participant Types', category: 'events')]
class ListParticipantTypesTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_participant_types',
            description: 'Lists the participant-type catalog (names + ids). Call this to resolve a participant-type '
                . 'name the user mentions before using it as an include/exclude filter in get_event_report.',
        );
    }

    /**
     * @return array<int, \NeuronAI\Tools\ToolProperty>
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
        $types = ParticipantType::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'count' => $types->count(),
            'participant_types' => $types->map(fn (ParticipantType $t): array => [
                'id' => $t->getId(),
                'name' => $t->name,
            ])->all(),
        ];
    }
}
