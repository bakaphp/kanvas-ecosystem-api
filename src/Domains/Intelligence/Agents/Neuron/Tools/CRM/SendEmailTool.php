<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\PersistOutboundMessageAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\SendsEmailToPeople;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Send Email To Lead', category: 'crm')]
class SendEmailTool extends Tool
{
    use ResolvesLeadForTool;
    use SendsEmailToPeople;

    protected string $name = 'send_lead_email';

    protected ?string $description = 'LEADS ONLY: requires a lead_id. Send an email to the prospect/customer on that lead, at the email address already on file. '
        . 'It cannot email a person who has no lead or a teammate. '
        . 'Use it when they ask you to email them something (a quote, a summary, confirmation details, links, next steps) '
        . 'or when what they need is too long to send over chat/SMS. '
        . 'You cannot choose the primary recipient — the email always goes to the address on the lead. '
        . 'You may optionally cc other people who are ALREADY contacts on this lead, its participants '
        . '(co-buyers, spouses, referrers) or its organization (e.g. a second decision-maker), '
        . 'or addresses on an email domain the business allowed for CC (e.g. a teammate); '
        . 'any other address is ignored. '
        . 'This is NOT for internal messages to staff (use take_message) and NOT a way to keep chatting: '
        . 'still answer the person in the conversation after sending.';

    /** Lead custom field the Mailgun responder and the follow-up engine read as the email thread subject. */
    private const string THREAD_SUBJECT_ANCHOR = 'title_email_follow_up';

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
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Subject line of the email. Short and specific to what the person asked for.',
                required: true,
            ),
            new ToolProperty(
                name: 'body',
                type: PropertyType::STRING,
                description: 'The email body, written to the prospect in the first person on behalf of the business. '
                    . 'Markdown is supported (headings, bold, lists, links) and is rendered to HTML. '
                    . 'Do not add a greeting header image, a signature, or "Sent by AI" — the template handles branding. '
                    . 'For internal assistants, the sender’s enabled email signature is added automatically, never the customer-facing agent’s signature.',
                required: true,
            ),
            new ToolProperty(
                name: 'cc',
                type: PropertyType::STRING,
                description: 'Optional. Comma-separated email addresses to CC on this email. '
                    . 'Only addresses that already exist as contacts on this lead, on one of its participants '
                    . '(co-buyer, spouse, referrer), on its organization, or on an email domain the business '
                    . 'allowed for CC are accepted — any other address is ignored (you cannot CC arbitrary people). '
                    . 'Leave empty to email only the primary contact.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $lead_id,
        string $subject,
        string $body,
        ?string $cc = null,
    ): array {
        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '' || $body === '') {
            return [
                'status' => 'error',
                'message' => 'Both subject and body are required — write the email before calling send_lead_email.',
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
                'message' => 'This person asked not to be contacted. Do not email them. Tell them you cannot send it and offer to have a human help.',
            ];
        }

        $contact = $this->deliverableEmailContact($lead->people);
        if ($contact === null) {
            return [
                'status' => 'error',
                'message' => $this->noDeliverableEmailMessage($lead->people, 'update_lead'),
            ];
        }

        $ccResult = $this->resolveCcRecipients($this->ccCandidatePeopleIds($lead), $cc, $contact->value);

        try {
            $sent = new SendMessageToLeadAction($lead)->withSignatureOwner($this->signatureOwner())->execute(
                channel: LeadCommunicationChannelEnum::EMAIL->value,
                message: $body,
                title: $subject,
                to: $contact->value,
                cc: $ccResult['accepted'],
                fromAgent: $this->contextAgent(),
            );
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'The email could not be sent right now. Do not retry — tell the person you will follow up and use hand_off if it is urgent.',
            ];
        }

        $persisted = new PersistOutboundMessageAction(
            entity: $lead,
            user: $this->contextUser() ?? $lead->company->getAiAgentUserOrFail(),
            channelType: LeadCommunicationChannelEnum::EMAIL->value,
            recipient: $contact->value,
            content: $body,
            subject: $subject,
            agent: $this->contextAgent(),
        )->execute($sent);

        // First touch wins: the inbound Mailgun responder and the cron follow-up engine both read
        // title_email_follow_up as their outbound subject, so anchoring here is what keeps the reply
        // and every later follow-up in one email thread. Never overwrite an existing anchor.
        if (trim((string) $lead->get(self::THREAD_SUBJECT_ANCHOR)) === '') {
            $lead->set(self::THREAD_SUBJECT_ANCHOR, $subject);
        }

        $recipients = $contact->value;
        if ($ccResult['accepted'] !== []) {
            $recipients .= ' (cc: ' . implode(', ', $ccResult['accepted']) . ')';
        }

        new RecordLeadNoteAction($lead)->execute(
            'Emailed the prospect at ' . $recipients . ' — "' . $subject . '"' . "\n\n" . $body,
            'agent-email',
        );

        $note = 'Email sent and logged on the lead. Tell the person it is on the way, in one short line.'
            . $this->rejectedCcNote(
                $ccResult['rejected'],
                'this lead, its participants or its organization',
                'add them as a contact on the lead, a participant or the organization first'
            );

        return [
            'status' => 'success',
            'lead_id' => $lead->getId(),
            'from' => $sent['from'] ?? null,
            'to' => $contact->value,
            'cc' => $ccResult['accepted'],
            'cc_rejected' => $ccResult['rejected'],
            'subject' => $subject,
            'body_length' => strlen($body),
            'message_id' => $persisted['message']->getId(),
            'channel_id' => $persisted['channel']->getId(),
            'attachments_count' => $sent['attachments_count'] ?? 0,
            'note' => $note,
        ];
    }

    /**
     * The lead's person, its participants (co-buyers, spouses, referrers) and its organization's people.
     *
     * @return array<int, int>
     */
    private function ccCandidatePeopleIds(Lead $lead): array
    {
        $ids = [];

        if ($lead->people !== null) {
            $ids[] = $lead->people->getId();
        }

        foreach ($lead->participants()->notDeleted()->pluck('peoples_id') as $peopleId) {
            $ids[] = (int) $peopleId;
        }

        foreach ($lead->organization?->peoples ?? [] as $person) {
            $ids[] = $person->getId();
        }

        return array_values(array_unique($ids));
    }
}
