<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\TakesMessageForEntity;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Take Message', category: 'crm')]
class TakeMessageTool extends Tool
{
    use HasKanvasContext;
    use ResolvesLeadForTool;
    use TakesMessageForEntity;

    public function __construct()
    {
        parent::__construct(
            name: 'take_message',
            description: 'Take a message for the business / a staff member and make sure they get it. '
                . 'Use this when the prospect wants to leave a message, ask someone to call them back, or pass something along '
                . '("tell John I called", "have someone reach me about my invoice"). '
                . 'The message is saved as a note on the lead and the assigned owner is notified. '
                . 'This does NOT transfer the conversation — use handoff_lead for that.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead in scope for this conversation.',
                required: true,
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'The message to pass along, in the prospect\'s words.',
                required: true,
            ),
            new ToolProperty(
                name: 'for_whom',
                type: PropertyType::STRING,
                description: 'Who the message is for, if the prospect named someone (e.g. "John", "the service dept"). Omit if not specified.',
                required: false,
            ),
            new ToolProperty(
                name: 'callback_number',
                type: PropertyType::STRING,
                description: 'A phone/number the prospect wants to be reached at, if they gave one.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $lead_id,
        string $message,
        ?string $for_whom = null,
        ?string $callback_number = null,
    ): array {
        if (trim($message) === '') {
            return $this->emptyMessageError();
        }

        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $message = trim($message);
        $forWhom = $this->normalizeOptional($for_whom);
        $callback = $this->normalizeOptional($callback_number);

        $body = $this->receptionistMessageBody($message, $forWhom, $callback);
        $recorded = new RecordLeadNoteAction($lead)->execute($body, 'receptionist-note', $this->actingNoteUser()) !== null;

        return $this->finalizeTakenMessage($lead, 'lead_id', $message, $forWhom, $callback, $recorded);
    }
}
