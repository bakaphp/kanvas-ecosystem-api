<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Baka\Support\Str;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Override;

/**
 * An agent's answer to an inbound message, on the conversation the message arrived on. The medium,
 * the counterpart and the sending connection all come from the conversation; the reply is filed
 * first (and may be held for approval), then delivered by the facade on that same message.
 */
class AgentChannelResponderAction extends BaseAgentChannelReplyAction
{
    public function __construct(
        Channel $channel,
        Message $message,
        Agent $agent,
        ?Session $session = null
    ) {
        parent::__construct(
            $channel,
            $message,
            $agent,
            $session
        );

        // The reply is filed as the same kind of message it answers, so whatever reads a
        // conversation by type (the CRM's channel views, the delivery status jobs) finds both.
        $this->communicationChannel = $this->medium()->value;
        $this->messageTypeVerb = $message->messageType?->verb ?? $this->communicationChannel;
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $lead = $this->message->entity(Lead::class);

        if ($this->message->entity(People::class) === null && $lead === null) {
            throw new ValidationException('No entity found');
        }

        // A closed burst is one turn: every text in it reaches the agent as a single prompt. An empty
        // body is still a turn — a picture sent without words is described to the agent separately.
        $prompt = Str::trimToNull($params['burst_text'] ?? null)
            ?? (string) ($this->message->message['content'] ?? '');

        $response = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $prompt,
            user: $this->message->company->getAiAgentUserOrFail(),
            currentLead: $lead instanceof Lead ? $lead : null,
            sourceChannel: $this->channel,
            sourceMessage: $this->message,
            persistConversation: false,
        )->execute();

        // Before the reply is filed, so a reply held for approval stores the body it will ship.
        $text = $this->prepareReply(ChatHelper::extractTextFromResponse($response));
        $to = $this->hijackMessagePhone($this->recipient());

        $reply = $this->createMessage(
            $text,
            $to,
            $this->message,
            $this->channel,
            $this->senderAddress()
        );

        if (! $reply->is_locked) {
            $this->deliver($to, $text, $reply);
        }

        return [
            'message' => $prompt,
            'responseText' => $response,
            'response' => $text,
        ];
    }

    protected function medium(): ChannelTypeEnum
    {
        return ChannelTypeEnum::tryFrom((string) ($this->message->message['communication']['channel'] ?? ''))
            ?? throw new AgentReplySkippedException('The message did not arrive on a connection');
    }

    /**
     * The counterpart, as the conversation recorded it when the message arrived.
     */
    protected function recipient(): string
    {
        return Str::trimToNull($this->message->message['communication']['thread'] ?? null)
            ?? throw new AgentReplySkippedException('The conversation has no counterpart address');
    }

    protected function senderAddress(): ?string
    {
        return $this->connection()?->account_key;
    }

    /**
     * A customer who writes to us cold still gets the opt-out clause on our first text.
     */
    protected function prepareReply(string $text): string
    {
        return in_array($this->medium(), [ChannelTypeEnum::SMS, ChannelTypeEnum::MMS], true)
            ? SmsOptOutNoticeService::appendIfFirstOutbound($this->channel, $text)
            : $text;
    }

    /**
     * Sent synchronously so the activity's retries cover the send. The consent guards and the
     * connection's switch are checked at the moment of sending, not when the turn started.
     */
    protected function deliver(string $to, string $text, Message $reply): void
    {
        $delivery = Communication::for($this->message->app, $this->message->company)
            ->reply(
                $this->message,
                $text,
                from: Sender::agent($this->agent),
                sync: true,
                filedAs: $reply
            )
            ->delivery($this->medium());

        if ($delivery?->recipientOptedOut() === true) {
            $this->recordOptOutNote($to, $text);
        }
    }

    /**
     * The recipient unsubscribed with the provider itself. Expected, not a fault: the person is
     * opted out and the attempt is left on the lead for whoever reads it next.
     */
    protected function recordOptOutNote(string $to, string $text): void
    {
        $entity = $this->message->entity(Lead::class);

        if (! $entity instanceof Lead) {
            return;
        }

        $entity->people?->optOutPhoneContacts();

        new RecordLeadNoteAction($entity)->execute(
            "Message not delivered: {$to} has opted out of messages. Attempted reply: \"{$text}\"",
            'sms-opt-out',
        );
    }

    private function connection(): ?Connection
    {
        return Connection::forFiledMessage($this->message);
    }
}
