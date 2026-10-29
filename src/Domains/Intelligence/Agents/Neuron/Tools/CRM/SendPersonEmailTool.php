<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Guild\Customers\Actions\SendEmailToPeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\ConsentGuard;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Intelligence\Agents\Actions\Outreach\PersistOutboundMessageAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\SendsEmailToPeople;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * send_lead_email for a contact that has no lead. Internal agents only: it reaches any person in the
 * company by id, which on a customer surface would let one prospect steer mail to another.
 */
#[AgentTool(name: 'Send Email To Person', category: 'crm')]
class SendPersonEmailTool extends Tool implements RequiresSystemAgent
{
    use ReportsToolOutcome;
    use ResolvesPersonForTool;
    use SendsEmailToPeople;

    protected string $name = 'send_person_email';

    protected ?string $description = 'Send an email to a CRM contact (a person, by person_id) at the email address already on file — '
        . 'for a contact that has no lead; a contact with a lead is emailed through the lead so the thread stays on it. '
        . 'You cannot choose the primary recipient — the email always goes to the address on the person. '
        . 'You may optionally cc other contacts of this person or of the organizations they belong to, '
        . 'or addresses on an email domain the business allowed for CC (e.g. a teammate); any other address is ignored. '
        . 'This is NOT for internal messages to staff.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the person (CRM contact) to email.',
                required: true,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Subject line of the email. Short and specific to what was asked for.',
                required: true,
            ),
            new ToolProperty(
                name: 'body',
                type: PropertyType::STRING,
                description: 'The email body, written to the person in the first person on behalf of the business. '
                    . 'Markdown is supported (headings, bold, lists, links) and is rendered to HTML. '
                    . 'Do not add a greeting header image, a signature, or "Sent by AI" — the template handles branding. '
                    . 'The sender’s enabled email signature is added automatically.',
                required: true,
            ),
            new ToolProperty(
                name: 'cc',
                type: PropertyType::STRING,
                description: 'Optional. Comma-separated email addresses to CC on this email. '
                    . 'Only contacts of this person, contacts of an organization they belong to, or addresses on '
                    . 'an email domain the business allowed for CC are accepted — any other address is ignored '
                    . '(you cannot CC arbitrary people). Leave empty to email only the person.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $person_id,
        string $subject,
        string $body,
        ?string $cc = null,
    ): array {
        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '' || $body === '') {
            return $this->invalidArgs('Both subject and body are required — write the email before calling send_person_email.');
        }

        $result = $this->resolvePersonOrError($person_id);
        if (is_array($result)) {
            return $result;
        }
        $person = $result;

        if (ConsentGuard::isPersonDoNotContact($person)) {
            return $this->denied(
                'This person asked not to be contacted. Nothing was sent.',
                guidance: 'Say plainly the email was NOT sent and offer to have a human help.',
            );
        }

        $contact = $this->deliverableEmailContact($person);
        if ($contact === null) {
            return $this->invalidArgs($this->noDeliverableEmailMessage($person, 'manage_person_contact'));
        }

        $ccResult = $this->resolveCcRecipients($this->ccCandidatePeopleIds($person), $cc, $contact->value);

        try {
            $sent = new SendEmailToPeopleAction($person)->execute(
                to: $contact->value,
                message: $body,
                subject: $subject,
                fromAgent: $this->contextAgent(),
                signatureOwner: $this->signatureOwner(),
                cc: $ccResult['accepted'],
            );
        } catch (Throwable $e) {
            report($e);

            return $this->denied(
                'The email could not be sent right now. Nothing was sent.',
                guidance: 'Do not retry — tell the person it failed and that someone will follow up.',
            );
        }

        $persisted = new PersistOutboundMessageAction(
            entity: $person,
            user: $this->contextUser() ?? $person->company->getAiAgentUserOrFail(),
            channelType: LeadCommunicationChannelEnum::EMAIL->value,
            recipient: $contact->value,
            content: $body,
            subject: $subject,
            agent: $this->contextAgent(),
        )->execute($sent);

        $recipients = $contact->value;
        if ($ccResult['accepted'] !== []) {
            $recipients .= ' (cc: ' . implode(', ', $ccResult['accepted']) . ')';
        }

        new RecordPeopleNoteAction($person)->execute(
            'Emailed ' . $recipients . ' — "' . $subject . '"' . "\n\n" . $body,
            'agent-email',
        );

        return $this->ok([
            'person_id' => $person->getId(),
            'from' => $sent['from'] ?? null,
            'to' => $contact->value,
            'cc' => $ccResult['accepted'],
            'cc_rejected' => $ccResult['rejected'],
            'subject' => $subject,
            'message_id' => $persisted['message']->getId(),
            'channel_id' => $persisted['channel']->getId(),
        ], guidance: 'Email sent and logged on the person. Tell them it is on the way, in one short line.'
            . $this->rejectedCcNote(
                $ccResult['rejected'],
                'this person or the organizations they belong to',
                'add them as a contact on this person or one of their organizations first'
            ));
    }

    /**
     * The person and everyone in the organizations they belong to.
     *
     * @return array<int, int>
     */
    private function ccCandidatePeopleIds(People $person): array
    {
        $ids = [$person->getId()];

        foreach ($person->organizations()->get() as $organization) {
            foreach ($organization->peoples as $member) {
                $ids[] = $member->getId();
            }
        }

        return array_values(array_unique($ids));
    }
}
