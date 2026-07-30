<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
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

#[AgentTool(name: 'Send Email', category: 'crm')]
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
                . 'You cannot choose the primary recipient — the email always goes to the address on the lead. '
                . 'You may optionally cc other people who are ALREADY contacts on this lead or its organization '
                . '(e.g. a second decision-maker); addresses that are not on file are ignored. '
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
            new ToolProperty(
                name: 'cc',
                type: PropertyType::STRING,
                description: 'Optional. Comma-separated email addresses to CC on this email. '
                    . 'Only addresses that already exist as contacts on this lead or its organization are allowed — '
                    . 'any address not on file is silently ignored (you cannot CC arbitrary people). '
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

        $ccResult = $this->resolveCcRecipients($lead, $cc, $contact->value);

        try {
            $sent = new SendMessageToLeadAction($lead)->execute(
                channel: LeadCommunicationChannelEnum::EMAIL->value,
                message: $body,
                title: $subject,
                to: $contact->value,
                cc: $ccResult['accepted'],
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

        $recipients = $contact->value;
        if ($ccResult['accepted'] !== []) {
            $recipients .= ' (cc: ' . implode(', ', $ccResult['accepted']) . ')';
        }

        new RecordLeadNoteAction($lead)->execute(
            'Emailed the prospect at ' . $recipients . ' — "' . $subject . '"' . "\n\n" . $body,
            'agent-email',
        );

        $note = 'Email sent and logged on the lead. Tell the person it is on the way, in one short line.';
        if ($ccResult['rejected'] !== []) {
            $note .= ' These CC addresses are not on file for this lead or its organization and were NOT copied: '
                . implode(', ', $ccResult['rejected'])
                . '. To include them, add them as a contact on the lead or organization first.';
        }

        return [
            'status' => 'success',
            'lead_id' => $lead->getId(),
            'to' => $contact->value,
            'cc' => $ccResult['accepted'],
            'cc_rejected' => $ccResult['rejected'],
            'subject' => $subject,
            'body_length' => strlen($body),
            'attachments_count' => $sent['attachments_count'] ?? 0,
            'note' => $note,
        ];
    }

    /**
     * Split the requested CC addresses into the ones we will actually copy and the ones we drop.
     * An address is accepted only if it matches — case-insensitively — a deliverable email contact
     * already on file for the lead's person or its organization; the primary recipient and duplicates
     * are removed. This is the anti-exfiltration guarantee: CC can widen delivery to known contacts,
     * never to an arbitrary address the model was told to add.
     *
     * @return array{accepted: array<int, string>, rejected: array<int, string>}
     */
    private function resolveCcRecipients(Lead $lead, ?string $cc, string $primaryEmail): array
    {
        $requested = $this->parseEmailList((string) $cc);
        if ($requested === []) {
            return ['accepted' => [], 'rejected' => []];
        }

        $allowed = $this->allowedCcContacts($lead);
        $primaryNormalized = strtolower(trim($primaryEmail));

        $accepted = [];
        $rejected = [];
        $seen = [];

        foreach ($requested as $email) {
            $normalized = strtolower(trim($email));
            if ($normalized === '' || $normalized === $primaryNormalized || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            if (isset($allowed[$normalized])) {
                $accepted[] = $allowed[$normalized];
            } else {
                $rejected[] = $email;
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * Deliverable email addresses on file across the lead's person and its organization's people,
     * keyed by lowercase value → the stored (canonical-cased) value we actually send to.
     *
     * @return array<string, string>
     */
    private function allowedCcContacts(Lead $lead): array
    {
        $allowed = [];

        $collect = function (?People $people) use (&$allowed): void {
            if ($people === null) {
                return;
            }

            $contacts = $people->contacts()
                ->whereIn('contacts_types_id', self::EMAIL_CONTACT_TYPES)
                ->deliverable()
                ->get();

            foreach ($contacts as $contact) {
                $allowed[strtolower(trim($contact->value))] = $contact->value;
            }
        };

        $collect($lead->people);

        foreach ($lead->organization?->peoples ?? [] as $person) {
            $collect($person);
        }

        return $allowed;
    }

    /**
     * @return array<int, string>
     */
    private function parseEmailList(string $raw): array
    {
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
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
