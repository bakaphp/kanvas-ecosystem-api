<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Outlook;

use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Microsoft\Client;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Intelligence\Agents\Actions\Outreach\PersistOutboundMessageAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\SendsEmailToPeople;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTeammates;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RequiresHumanCaller;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Sends from the Outlook mailbox connected to the company (the `microsoft` integration), so the email
 * leaves from that account's own address and lands in its Sent Items.
 *
 * Only a person can make it send, and only after seeing the email. The first call stores a draft and
 * sends nothing; the send is a second call with the draft id, accepted only from a later turn than the
 * one that drafted it, so the model cannot draft and confirm on its own. A turn is one instance: the
 * agent builds its tools once per run and NeuronAI clones that instance for every call, which is the
 * same fact `GuardsRepeatCalls` relies on. The draft is the person's and is sent as drafted, once.
 *
 * The recipients are typed by the model, so each one must be in a closed set ("Destination safety" in
 * Agents/CLAUDE.md): a user of the company, a deliverable email of a person in the company's CRM who
 * has not asked to stop, or an address on a domain the admin allowlisted. One address outside it
 * refuses the whole email, so it never reaches only part of its audience.
 */
#[AgentTool(name: 'Send Outlook Email', category: 'productivity')]
class SendOutlookEmailTool extends Tool implements RequiresSystemAgent
{
    use FindsTeammates;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use RequiresHumanCaller;
    use SendsEmailToPeople;
    use TrackByInputs;

    private const int DRAFT_TTL_SECONDS = 1800;

    // `send_` keeps it under ApprovalPolicy's outbound prefix gate.
    protected string $name = 'send_outlook_email';

    protected ?string $description = 'Sends an email from the Outlook mailbox connected to this company, so it goes out '
        . 'from that account\'s own address and is saved in its Sent Items. Use it ONLY when the person you are '
        . 'talking to asked you to send an email from their Outlook / their own email; never on your own initiative. '
        . 'It works in two steps and never sends on the first: (1) call it with to, subject and body to create a '
        . 'draft, then show the person the exact recipients, subject and body and ask them to confirm; (2) only '
        . 'after they reply confirming, call it again with just the draft_id. A confirmation in the same turn as '
        . 'the draft is refused. If they want changes, draft again. Recipients must be users of this company, '
        . 'contacts already in the CRM, or on a domain the admin allowed. Write the body in Markdown.';

    private readonly string $turn;

    public function __construct()
    {
        $this->turn = bin2hex(random_bytes(8));
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'to',
                type: PropertyType::STRING,
                description: 'Step 1. Recipient email addresses, comma-separated.',
                required: false,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Step 1. Subject line.',
                required: false,
            ),
            new ToolProperty(
                name: 'body',
                type: PropertyType::STRING,
                description: 'Step 1. The email body in Markdown, written in the first person as the mailbox owner.',
                required: false,
            ),
            new ToolProperty(
                name: 'cc',
                type: PropertyType::STRING,
                description: 'Step 1, optional. Email addresses to CC, comma-separated. Same rules as the recipients.',
                required: false,
            ),
            new ToolProperty(
                name: 'draft_id',
                type: PropertyType::STRING,
                description: 'Step 2. The draft_id from step 1, passed alone and only after the person confirmed it.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $to = null,
        ?string $subject = null,
        ?string $body = null,
        ?string $cc = null,
        ?string $draft_id = null
    ): array {
        $caller = $this->humanCallerOrDenial(
            'send an email from Outlook',
            'Say plainly that NOTHING was sent. Only a person can ask for this email, from their own chat.'
        );

        if (is_array($caller)) {
            return $caller;
        }

        $draftId = Str::trimToNull($draft_id);

        return $draftId === null
            ? $this->draft(
                $caller,
                $to,
                $subject,
                $body,
                $cc
            )
            : $this->sendDraft($caller, $draftId);
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(
        Users $caller,
        ?string $to,
        ?string $subject,
        ?string $body,
        ?string $cc
    ): array {
        $recipients = $this->addresses($to);
        $copies = $this->addresses($cc);
        $subject = Str::trimToNull($subject);
        $body = (string) Str::trimToNull($body);

        if ($recipients === [] || $subject === null || $body === '') {
            return $this->invalidArgs(
                'to, subject and body are required to draft the email. Nothing was sent.',
                ['reason' => 'invalid_input']
            );
        }

        ['refused' => $refused] = $this->checkRecipients([...$recipients, ...$copies]);

        if ($refused !== []) {
            return $this->recipientsDenied($refused);
        }

        $draftId = bin2hex(random_bytes(6));
        $draft = ['to' => $recipients, 'cc' => $copies, 'subject' => $subject, 'body' => $body];

        Cache::put(
            $this->draftKey($caller, $draftId),
            [...$draft, 'turn' => $this->turn],
            self::DRAFT_TTL_SECONDS
        );

        return $this->noop(
            ['sent' => false, 'draft_id' => $draftId, ...$draft],
            guidance: 'NOTHING has been sent yet. Show the person the exact recipients, subject and body above and '
                . 'ask them to confirm. Only after they reply confirming, call send_outlook_email with just this '
                . 'draft_id. If they want changes, draft it again.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sendDraft(Users $caller, string $draftId): array
    {
        $key = $this->draftKey($caller, $draftId);
        $draft = Cache::get($key);

        if (! is_array($draft)) {
            return $this->notFound(
                ['success' => false, 'sent' => false, 'reason' => 'draft_not_found'],
                guidance: 'This draft does not exist, expired, was already sent, or belongs to someone else. '
                    . 'Nothing was sent. Draft the email again and show it to the person.'
            );
        }

        if ($draft['turn'] === $this->turn) {
            return $this->denied(
                'Nothing was sent: the person has not confirmed this draft yet.',
                ['sent' => false, 'reason' => 'awaiting_user_confirmation'],
                guidance: 'Show the person the draft and wait for their reply. Only their confirmation in a new '
                    . 'message lets you send it.'
            );
        }

        ['refused' => $refused, 'contacts' => $contacts] = $this->checkRecipients([...$draft['to'], ...$draft['cc']]);

        if ($refused !== []) {
            return $this->recipientsDenied($refused);
        }

        try {
            $accessToken = Client::getValidAccessToken($this->app, $this->company);
        } catch (Throwable) {
            return $this->failed(
                'Nothing was sent: this company has no Outlook mailbox connected.',
                ['sent' => false, 'reason' => OneDriveErrorReasonEnum::NOT_CONNECTED->value],
                guidance: 'Ask an admin to connect Microsoft in the integrations screen.'
            );
        }

        try {
            Client::sendMail(
                $accessToken,
                $draft['to'],
                $draft['subject'],
                MarkdownEmailRenderer::toEmailHtml($draft['body'], allowHtml: false),
                ccRecipients: $draft['cc'],
            );
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'Nothing was sent: Outlook refused the email. ' . $e->getMessage(),
                ['sent' => false, 'reason' => 'request_failed']
            );
        }

        Cache::forget($key);
        $this->recordOnPeople(
            $contacts,
            $caller,
            $draft['subject'],
            $draft['body']
        );

        return $this->ok(['sent' => true, 'to' => $draft['to'], 'cc' => $draft['cc'], 'subject' => $draft['subject']]);
    }

    /**
     * @param list<string> $refused
     * @return array<string, mixed>
     */
    private function recipientsDenied(array $refused): array
    {
        return $this->denied(
            'Nothing was sent. These addresses are not users of this company, contacts in the CRM, or on an '
                . 'allowed domain (or they asked not to be contacted): ' . implode(', ', $refused) . '.',
            ['sent' => false, 'reason' => 'recipient_not_allowed', 'refused' => $refused],
            guidance: 'Tell the person plainly. To email them, add them as a contact in the CRM first, or ask an '
                . 'admin to allow their domain. Do not send it any other way.'
        );
    }

    /**
     * @param list<string> $addresses
     * @return array{refused: list<string>, contacts: list<Contact>}
     */
    private function checkRecipients(array $addresses): array
    {
        $agent = $this->contextAgent();
        $allowedDomains = $this->allowedCcDomains();
        $refused = [];
        $contacts = [];

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $refused[] = $address;

                continue;
            }

            $contact = $this->companyEmailContact($address);

            if ($contact !== null) {
                $contacts[] = $contact;
            } elseif (($agent === null || $this->teammateByEmail($address, $agent) === null)
                && ! $this->isAllowedDomainCc(strtolower($address), $allowedDomains)) {
                $refused[] = $address;
            }
        }

        return ['refused' => $refused, 'contacts' => $contacts];
    }

    /**
     * Outlook's Sent Items is invisible to the activity timeline and to the agent's next turn, so each
     * CRM person reached gets the email on their own channel. The email already left: a failed record
     * is reported, never turned into a failed send the model would retry.
     *
     * @param list<Contact> $contacts
     */
    private function recordOnPeople(
        array $contacts,
        Users $sender,
        string $subject,
        string $body
    ): void {
        foreach ($contacts as $contact) {
            try {
                new PersistOutboundMessageAction(
                    entity: $contact->people,
                    user: $sender,
                    channelType: LeadCommunicationChannelEnum::EMAIL->value,
                    recipient: $contact->value,
                    content: $body,
                    subject: $subject,
                    agent: $this->contextAgent(),
                )->execute(['provider' => 'microsoft']);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function draftKey(Users $caller, string $draftId): string
    {
        return sprintf(
            'outlook-email-draft:%d:%d:%d:%s',
            $this->app->getId(),
            $this->company->getId(),
            $caller->getId(),
            $draftId
        );
    }

    /**
     * @return list<string>
     */
    private function addresses(?string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $list))));
    }
}
