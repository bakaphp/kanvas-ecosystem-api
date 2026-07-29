<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Closure;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Send SMS')]
class SendSmsTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct(
        private readonly ?Closure $sender = null,
    ) {
        parent::__construct(
            name: 'send_sms',
            description: 'Send one SMS to the phone already stored on a lead. '
                . 'Use this only after the complete customer-facing message is ready. Call it at most once per message. '
                . 'The recipient cannot be supplied or changed. For email, use send_email.',
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
                description: 'The final customer-facing message to deliver.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $lead_id,
        string $message,
    ): array {
        $message = trim($message);
        if ($message === '') {
            return [
                'status' => 'error',
                'message' => 'The message is empty. Write the complete message before calling send_sms.',
            ];
        }

        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        if ((bool) $lead->get('do_not_contact')) {
            return [
                'status' => 'error',
                'message' => 'This lead is marked do not contact. Do not send the message.',
            ];
        }

        $contact = $this->resolvePhoneContact($lead);
        if ($contact === null) {
            return [
                'status' => 'error',
                'message' => 'This lead has no deliverable phone number or has opted out. Do not send the message.',
            ];
        }

        try {
            $sent = $this->send($lead, $message, $contact->value);
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'The message could not be sent right now. Do not retry automatically.',
            ];
        }

        new RecordLeadNoteAction($lead)->execute(
            'SMS sent to the prospect: ' . $message,
            'agent-sms',
        );

        return [
            'status' => 'success',
            'lead_id' => $lead->getId(),
            'channel' => LeadCommunicationChannelEnum::SMS->value,
            'to' => $contact->value,
            'message_length' => strlen($message),
            'provider_response' => $sent,
        ];
    }

    private function resolvePhoneContact(Lead $lead): ?Contact
    {
        return $lead->people
            ?->contacts()
            ->whereIn('contacts_types_id', Contact::PHONE_TYPES)
            ->where('is_opt_out', 0)
            ->orderByDesc('weight')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function send(Lead $lead, string $message, string $to): array
    {
        if ($this->sender !== null) {
            return ($this->sender)($lead, $message, $to);
        }

        return new SendMessageToLeadAction($lead)->execute(
            channel: LeadCommunicationChannelEnum::SMS->value,
            message: $message,
            to: $to,
        );
    }
}
