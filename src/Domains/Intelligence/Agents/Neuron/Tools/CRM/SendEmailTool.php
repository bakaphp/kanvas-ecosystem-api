<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
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

/**
 * The recipient is never a tool parameter — it is resolved from the lead's own contacts. An LLM that
 * can pick the destination address is an exfiltration path (prompt-inject the prospect's chat, get the
 * quote mailed to attacker@evil.com), so the model composes the email and the lead decides who gets it.
 */
#[AgentTool(name: 'Send Email')]
class SendEmailTool extends Tool
{
    use ResolvesLeadForTool;

    /** Lead custom field the Mailgun responder and the follow-up engine read as the email thread subject. */
    private const string THREAD_SUBJECT_ANCHOR = 'title_email_follow_up';

    private const array EMAIL_CONTACT_TYPES = [
        ContactTypeEnum::PRIMARY_EMAIL->value,
        ContactTypeEnum::EMAIL->value,
        ContactTypeEnum::SECONDARY_EMAIL->value,
    ];

    public function __construct()
    {
        parent::__construct(
            name: 'send_email',
            description: 'Send an email to the prospect/customer on this lead, at the email address already on file. '
                . 'Use it when they ask you to email them something (a quote, a summary, confirmation details, links, next steps) '
                . 'or when what they need is too long to send over chat/SMS. '
                . 'You cannot choose the recipient — the email always goes to the address on the lead. '
                . 'This is NOT for internal messages to staff (use take_message) and NOT a way to keep chatting: '
                . 'still answer the person in the conversation after sending.',
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
                    . 'Do not add a greeting header image, a signature, or "Sent by AI" — the template adds the branding and signature.',
                required: true,
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
    ): array {
        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '' || $body === '') {
            return [
                'status' => 'error',
                'message' => 'Both subject and body are required — write the email before calling send_email.',
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

        $contact = $this->resolveEmailContact($lead);
        if ($contact === null) {
            return [
                'status' => 'error',
                'message' => $this->noDeliverableEmailMessage($lead),
            ];
        }

        try {
            $sent = new SendMessageToLeadAction($lead)->execute(
                channel: LeadCommunicationChannelEnum::EMAIL->value,
                message: $body,
                title: $subject,
                to: $contact->value,
            );
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'The email could not be sent right now. Do not retry — tell the person you will follow up and use hand_off if it is urgent.',
            ];
        }

        // First touch wins: the inbound Mailgun responder and the cron follow-up engine both read
        // title_email_follow_up as their outbound subject, so anchoring here is what keeps the reply
        // and every later follow-up in one email thread. Never overwrite an existing anchor.
        if (trim((string) $lead->get(self::THREAD_SUBJECT_ANCHOR)) === '') {
            $lead->set(self::THREAD_SUBJECT_ANCHOR, $subject);
        }

        new RecordLeadNoteAction($lead)->execute(
            'Emailed the prospect at ' . $contact->value . ' — "' . $subject . '"' . "\n\n" . $body,
            'agent-email',
        );

        return [
            'status' => 'success',
            'lead_id' => $lead->getId(),
            'to' => $contact->value,
            'subject' => $subject,
            'body_length' => strlen($body),
            'attachments_count' => $sent['attachments_count'] ?? 0,
            'note' => 'Email sent and logged on the lead. Tell the person it is on the way, in one short line.',
        ];
    }

    /**
     * deliverable() is is_opt_out=0 AND not a permanent failure — so a hard-bounced or invalid address
     * (flagged by the Mailgun bounce webhook / email validation) is never emailed again. Soft bounces
     * stay eligible.
     */
    private function resolveEmailContact(Lead $lead): ?Contact
    {
        return $this->emailContacts($lead)
            ?->deliverable()
            ->orderByRaw('FIELD(contacts_types_id, ' . implode(',', self::EMAIL_CONTACT_TYPES) . ')')
            ->first();
    }

    private function emailContacts(Lead $lead): ?HasMany
    {
        return $lead->people
            ?->contacts()
            ->whereIn('contacts_types_id', self::EMAIL_CONTACT_TYPES);
    }

    /**
     * The three dead ends need different behaviour from the model: a bad address it can replace, an
     * opt-out it must never work around, and a missing address it should simply ask for.
     */
    private function noDeliverableEmailMessage(Lead $lead): string
    {
        $contacts = $this->emailContacts($lead)?->get();

        if ($contacts === null || $contacts->isEmpty()) {
            return 'This lead has no email address on file. Ask the person for the email address they want it sent to, '
                . 'save it with update_lead, then retry send_email.';
        }

        if ($contacts->contains(fn (Contact $contact): bool => ! $contact->isOptedOut())) {
            return 'The email address on file bounced or is invalid, so we cannot write to it. '
                . 'Ask the person to confirm a working email address, save it with update_lead, then retry send_email.';
        }

        return 'This person opted out of email. Do not email them and do not ask them for another address. '
            . 'Offer to help here in the conversation instead.';
    }
}
