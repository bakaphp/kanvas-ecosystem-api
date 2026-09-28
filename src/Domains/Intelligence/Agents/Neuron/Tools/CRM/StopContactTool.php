<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Actions\ApplyDoNotContactAction;
use Kanvas\Guild\Customers\Enums\ConsentMatchEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The half of opt-out handling that keywords cannot cover.
 *
 * ConsentKeywordService catches an exact "STOP" on the way in. The FCC requires honoring a
 * revocation made in "any reasonable manner", and Twilio explicitly does not block non-keyword
 * phrasing on our behalf — so "please take me off your list", "no me escribas más", "I'm not
 * interested, don't contact me again" reach the agent, and this tool is how the agent honors them.
 */
#[AgentTool(name: 'Stop Contact', category: 'crm')]
class StopContactTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'stop_contact',
            description: 'Honor a prospect who asks to stop being contacted / unsubscribe / "stop" / "remove me" / "do not contact". '
                . 'This is a full do-not-contact: it opts every phone AND email this person has out of future messages across '
                . 'SMS, WhatsApp and email, turns off automated replies on all of their leads, logs a note, and notifies a human. '
                . 'Call it as soon as the prospect clearly asks to stop hearing from the business. '
                . 'You may still send ONE short acknowledgement this turn (e.g. "Done — you won\'t hear from us again."), then stop.',
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
                name: 'reason',
                type: PropertyType::STRING,
                description: 'What the prospect said, in their words (stored for the owner\'s audit).',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $lead_id,
        ?string $reason = null,
    ): array {
        $result = $this->resolveLeadOrError($lead_id);

        if (is_array($result)) {
            return $result;
        }

        $lead = $result;
        $people = $lead->people;

        if ($people === null) {
            return [
                'status' => 'error',
                'message' => 'This lead has no person record, so there is nothing to opt out. '
                    . 'Tell the prospect a human will follow up and hand off instead.',
            ];
        }

        $outcome = new ApplyDoNotContactAction(
            people: $people,
            sourceChannel: 'agent_tool',
            lead: $lead,
            reason: $reason,
            match: ConsentMatchEnum::AGENT_TOOL,
        )->execute();

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'already_opted_out' => $outcome->alreadyOptedOut,
            'contacts_opted_out' => $outcome->contactsOptedOut,
            'leads_flagged' => $outcome->leadsFlagged,
            'ai_disabled' => true,
            'note' => $outcome->alreadyOptedOut
                ? 'This person was already opted out. Do not message them again; do not send another acknowledgement.'
                : 'Prospect opted out: every phone and email marked do-not-contact across all of their leads, '
                    . 'automated messaging disabled, note logged, team notified. '
                    . 'Send ONE brief acknowledgement, then do not message this person again.',
        ];
    }
}
