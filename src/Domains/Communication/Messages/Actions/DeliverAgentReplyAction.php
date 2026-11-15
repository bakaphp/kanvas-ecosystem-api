<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\EmailOptions;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Actions\Communication\RecordRecipientOptedOutNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;

/**
 * Sends an agent's answer that is already filed, in the conversation the message it answers arrived
 * on. The same send runs when the agent answers and, for a reply held for approval, when a person
 * approves it: everything it needs is on the two messages.
 */
final class DeliverAgentReplyAction
{
    /**
     * Where a filed reply keeps the id of the message it answers. A held draft outlives the turn
     * that wrote it, and this is how the approval finds the conversation again.
     */
    public const string ANSWERS_KEY = 'answers_message_id';

    public function __construct(
        private readonly Message $inbound,
        private readonly Message $reply,
        private readonly Agent $agent
    ) {
    }

    public static function forHeldReply(Message $reply): self
    {
        $inbound = Message::query()
            ->fromApp($reply->app)
            ->fromCompany($reply->company->getId())
            ->find((int) ($reply->message[self::ANSWERS_KEY] ?? 0));
        $agent = Agent::query()
            ->fromApp($reply->app)
            ->fromCompany($reply->company->getId())
            ->notDeleted()
            ->find((int) ($reply->message['agent_id'] ?? 0));

        if ($inbound === null || $agent === null) {
            throw new ValidationException(
                'Message ' . $reply->getId() . ' is not an agent reply to a conversation that can be answered.'
            );
        }

        return new self($inbound, $reply, $agent);
    }

    /**
     * Sent as a reply through the ledger, which reads the consent guards and the connection's switch
     * at the moment of sending, not when the turn started.
     */
    public function execute(): ?Delivery
    {
        $medium = ChannelTypeEnum::from((string) ($this->inbound->message['communication']['channel'] ?? ''));
        $text = (string) ($this->reply->message['content'] ?? '');
        $content = Content::text($text);
        $subject = $medium === ChannelTypeEnum::EMAIL ? $this->threadSubject() : null;

        if ($subject !== null) {
            $content = $content->withSubject($subject);
        }

        // From its own mailbox the agent writes the text as it is; from the company's address the
        // answer is the company's email.
        $ownMailbox = Connection::forFiledMessage($this->inbound)?->isPersonalAddressOf($this->agent) === true;

        if ($medium === ChannelTypeEnum::EMAIL && ! $ownMailbox) {
            $content = $content->withEmail(new EmailOptions(
                template: EmailComposer::REPLY_TEMPLATE,
                companySignature: true,
                leadId: $this->inbound->entity(Lead::class)?->getId()
            ));
        }

        $delivery = Communication::for($this->inbound->app, $this->inbound->company)
            ->reply(
                $this->inbound,
                $content,
                from: Sender::agent($this->agent),
                sync: true,
                filedAs: $this->reply
            )
            ->delivery($medium);

        if ($delivery?->recipientOptedOut() === true) {
            $this->recordOptOutNote((string) $delivery->to_address, $text);
        }

        return $delivery;
    }

    /**
     * A lead that wrote in cold has no outreach behind it, so its own subject becomes the anchor.
     * Without a lead the transport threads under the mail being answered.
     */
    private function threadSubject(): ?string
    {
        $lead = $this->inbound->entity(Lead::class);

        if (! $lead instanceof Lead) {
            return null;
        }

        if ($lead->emailThreadSubject() === null) {
            $inboundSubject = (string) (
                $this->inbound->message['subject']
                ?? $this->inbound->message['raw_data']['subject']
                ?? ''
            );
            $lead->anchorEmailThread((string) preg_replace('/^\s*re:\s*/i', '', $inboundSubject));
        }

        return $lead->emailThreadReplySubject();
    }

    /**
     * The recipient unsubscribed with the provider itself. Expected, not a fault: the ledger opted
     * that number out, and the attempt is left on the lead for whoever reads it next.
     */
    private function recordOptOutNote(string $to, string $text): void
    {
        $lead = $this->inbound->entity(Lead::class);

        if (! $lead instanceof Lead) {
            return;
        }

        new RecordRecipientOptedOutNoteAction($lead)->execute($to, $text);
    }
}
