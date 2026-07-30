<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Notifications\ReceptionistMessageNotification;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Take Message', category: 'crm')]
class TakeMessageTool extends Tool
{
    use ResolvesLeadForTool;

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
            return [
                'status' => 'error',
                'message' => 'The message is empty — capture what the prospect actually wants passed along before calling take_message.',
            ];
        }

        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $forWhom = $for_whom !== null && trim($for_whom) !== '' ? trim($for_whom) : null;
        $callback = $callback_number !== null && trim($callback_number) !== '' ? trim($callback_number) : null;

        $body = 'Receptionist message'
            . ($forWhom !== null ? ' for ' . $forWhom : '')
            . ': ' . trim($message)
            . ($callback !== null ? ' (callback: ' . $callback . ')' : '');

        $recorded = new RecordLeadNoteAction($lead)->execute($body, 'receptionist-note') !== null;

        $owner = $lead->owner;
        $notified = false;
        if ($owner !== null) {
            $owner->notify(new ReceptionistMessageNotification(
                $lead,
                [
                    'app' => $lead->app,
                    'company' => $lead->company,
                    'message' => trim($message),
                    'for_whom' => $forWhom,
                    'callback_number' => $callback,
                    'lead_id' => $lead->getId(),
                ],
            ));
            $notified = true;
        }

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'recorded' => $recorded,
            'owner_notified' => $notified,
            'note' => $notified
                ? 'Message saved as a note on the lead and the assigned owner was notified.'
                : 'Message saved as a note on the lead. No owner is assigned, so no one was pinged — consider handoff_lead if it is urgent.',
        ];
    }
}
