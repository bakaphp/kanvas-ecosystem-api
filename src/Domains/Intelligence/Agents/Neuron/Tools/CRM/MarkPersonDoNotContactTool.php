<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Actions\ApplyDoNotContactAction;
use Kanvas\Guild\Customers\Enums\ConsentMatchEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The staff-side counterpart of stop_contact, which needs a lead in scope. Apply-only on purpose:
 * re-subscribing stays narrow (RevokeDoNotContactAction, driven by the person's own START), see
 * src/Domains/Guild/Customers/CLAUDE.md.
 */
#[AgentTool(name: 'Mark Person Do Not Contact', category: 'crm')]
class MarkPersonDoNotContactTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use ResolvesPersonForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'mark_person_do_not_contact',
            description: 'Mark a person as do-not-contact: every email and phone they have is opted out across SMS, '
                . 'WhatsApp and email, automated replies are turned off on all of their leads, and a note is logged. '
                . 'Use when a teammate tells you this person must not be contacted. This cannot be undone from here.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'person_id', type: PropertyType::INTEGER, description: 'The id of the person.', required: true),
            new ToolProperty(name: 'reason', type: PropertyType::STRING, description: 'Why, in a short sentence.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $person_id, ?string $reason = null): array
    {
        $result = $this->resolvePersonOrError($person_id);
        if (is_array($result)) {
            return $result;
        }
        $person = $result;

        $outcome = new ApplyDoNotContactAction(
            people: $person,
            sourceChannel: 'agent_tool',
            reason: Str::trimToNull($reason),
            match: ConsentMatchEnum::AGENT_TOOL,
        )->execute();

        $payload = [
            'person_id' => $person->getId(),
            'contacts_opted_out' => $outcome->contactsOptedOut,
            'leads_flagged' => $outcome->leadsFlagged,
        ];

        if ($outcome->alreadyOptedOut) {
            return $this->noop($payload + ['message' => 'This person was already marked do-not-contact.']);
        }

        return $this->ok($payload + ['message' => 'Person marked do-not-contact.']);
    }
}
