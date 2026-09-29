<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ReadsActivityForEntity;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Read Person Activity', category: 'crm')]
class ReadPersonActivityTool extends Tool implements HasRunKey
{
    use ReadsActivityForEntity;
    use ResolvesPersonForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'read_person_activity',
            description: 'Read a contact\'s (person\'s) Activity thread — the notes, logged calls, emails and SMS the team '
                . 'sees on that contact, newest first. Use it before summarizing a contact or deciding how to reach them. '
                . 'It covers what was recorded on the person itself; each of their leads keeps its own activity.',
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
                description: 'The ID of the contact whose activity to read.',
                required: true,
            ),
            ...$this->paginationProperties(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $person_id, ?int $limit = null, ?int $before_id = null): array
    {
        $refusal = $this->customerSurfaceRefusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $person = $this->resolvePersonOrError($person_id);
        if (is_array($person)) {
            return $person;
        }

        return $this->readActivity(
            $person,
            'person_id',
            $person->getName(),
            $limit,
            $before_id
        );
    }
}
