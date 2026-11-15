<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions\Communication;

use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Actions\LinkDeliveryMessageAction;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * A message someone on our side filed in a lead's conversation (a person on the team, an AI that
 * runs outside Kanvas) goes to the customer on the medium the conversation is in.
 *
 * A person writing takes the lead over (the AI steps back); another AI does not. A message that
 * did not leave is noted on the lead (`failed`) and never counts as an answer; one with nothing to
 * send, or to a recipient who opted out, is neither delivered nor failed.
 */
final class DeliverConversationMessageAction
{
    public const string NOT_DELIVERED_TAG = 'reply-not-delivered';

    public function __construct(
        private readonly Channel $channel,
        private readonly Message $message,
        private readonly bool $writtenByPerson
    ) {
    }

    /**
     * @param string|null $from a number to text from, used when the company has it as a connection
     *
     * @return array{message: string, result: array<string, mixed>|null, delivered: bool, failed: bool}
     */
    public function execute(?string $from = null, ?string $title = null): array
    {
        $content = (string) ($this->message->message['content'] ?? '');
        $files = $this->message->getFiles();

        if ($content === '' && $files->isEmpty()) {
            return $this->skipped('Message content and files are both empty');
        }

        $lead = $this->message->preferredEntity(Lead::class);
        $lead = $lead instanceof Lead ? $lead : $this->channel->entityData();

        if (! $lead instanceof Lead) {
            return $this->skipped('The conversation is not a lead\'s');
        }

        $medium = $this->medium();
        // A number the caller named is its choice; otherwise the conversation's own number.
        $connection = $from === null ? $this->conversationConnection($medium) : null;

        if ($connection === null
            && ! Communication::for($lead->app, $lead->company)->isReady(ChannelTypeEnum::from($medium))
        ) {
            return $this->notDelivered(
                $lead,
                $medium,
                $content,
                'The company has no ' . $medium . ' connection to send from'
            );
        }

        // The agent's held draft is superseded by what was just written.
        $lastMessage = $this->channel->getLastMessage();
        if ($lastMessage && $lastMessage->isLocked() && strtolower((string) $lastMessage->messageType?->verb) !== 'note') {
            $this->channel->deleteLastMessageLocked();
        }

        if ($this->writtenByPerson) {
            $lead->announceAiTrigger(TriggersEnum::HUMAN_TAKEOVER->value);
            $lead->set(ConfigurationEnum::CONTACTED->value, LeadGroupStatusEnum::CONTACTED->value);
            $lead->setContactStatus(LeadGroupStatusEnum::CONTACTED);
        }

        // A WhatsApp message written here comes back through the provider's webhook as our own;
        // the one that started it is hidden so the conversation shows it once.
        if ($this->message->messageType?->verb === ChannelCategoryEnum::WHATSAPP->value) {
            $this->message->is_public = 0;
            $this->message->save();
        }

        $this->message->addTag('engagement');

        $body = $medium === LeadCommunicationChannelEnum::SMS->value
            ? SmsOptOutNoticeService::appendIfFirstOutbound($this->channel, $content, $this->message)
            : $content;

        try {
            $result = new SendMessageToLeadAction($lead)->inConversationOn($connection)->execute(
                channel: $medium,
                message: $body,
                from: $from,
                title: $title,
                signature: false,
                files: $files->isNotEmpty() ? $files : null
            );
        } catch (Throwable $exception) {
            $this->recordNotDeliveredNote(
                $lead,
                $medium,
                $content,
                $exception->getMessage()
            );

            throw $exception;
        }

        new LinkDeliveryMessageAction($this->message)->execute($result);

        if (($result['success'] ?? true) === false) {
            // A recipient who unsubscribed with the carrier already left its own note on the lead.
            return ($result['opted_out'] ?? false) === true
                ? $this->skipped('Recipient opted out of ' . $medium, $result)
                : $this->notDelivered(
                    $lead,
                    $medium,
                    $content,
                    (string) ($result['error'] ?? 'the provider refused it'),
                    $result
                );
        }

        // Written on whichever channel the rep had open: once it left, it is part of the lead's
        // history and the person's whole conversation as well.
        new LeadChannelService()->recordOnLeadAndPerson($this->message, $lead, $this->message->user);
        new MarkLeadMessagesAsRespondedAction($lead, $this->message)->execute();
        new NotifyLeadStakeholdersService($lead)->onAgentReply($this->message, isHuman: $this->writtenByPerson);

        return [
            'message' => 'Message sent to lead via ' . $medium,
            'result' => $result,
            'delivered' => true,
            'failed' => false,
        ];
    }

    /**
     * The number this conversation is already on: the one the customer last wrote to, or we last
     * wrote from. Answering from the company's default would reach them from a number they do not
     * know. Only while it can still send, and never for email, where the address a person writes
     * from is not the mailbox an agent was written to on.
     */
    private function conversationConnection(string $medium): ?Connection
    {
        if ($medium === LeadCommunicationChannelEnum::EMAIL->value) {
            return null;
        }

        $last = $this->channel->messages()
            ->where('messages.id', '!=', $this->message->getId())
            ->where('messages.is_deleted', 0)
            ->whereNotNull('messages.message->communication->connection_id')
            ->where('messages.message->communication->channel', $medium)
            ->orderByDesc('messages.id')
            ->first();

        $connection = $last !== null ? Connection::forFiledMessage($last) : null;

        return $connection?->unavailableReason() === null ? $connection : null;
    }

    private function medium(): string
    {
        return match ($this->message->messageType?->verb) {
            ChannelCategoryEnum::WHATSAPP->value => LeadCommunicationChannelEnum::WHATSAPP->value,
            ChannelCategoryEnum::EMAIL->value,
            ChannelCategoryEnum::MAILGUN->value => LeadCommunicationChannelEnum::EMAIL->value,
            default => LeadCommunicationChannelEnum::SMS->value,
        };
    }

    /**
     * @param array<string, mixed>|null $result
     *
     * @return array{message: string, result: array<string, mixed>|null, delivered: bool, failed: bool}
     */
    private function notDelivered(
        Lead $lead,
        string $medium,
        string $content,
        string $reason,
        ?array $result = null
    ): array {
        $this->recordNotDeliveredNote(
            $lead,
            $medium,
            $content,
            $reason
        );

        return ['failed' => true] + $this->skipped($reason, $result);
    }

    private function recordNotDeliveredNote(
        Lead $lead,
        string $medium,
        string $content,
        string $reason
    ): void {
        new RecordLeadNoteAction($lead)->execute(
            "The {$medium} reply was not delivered to the customer: {$reason}. Message: \"{$content}\"",
            self::NOT_DELIVERED_TAG,
        );
    }

    /**
     * @param array<string, mixed>|null $result
     *
     * @return array{message: string, result: array<string, mixed>|null, delivered: bool, failed: bool}
     */
    private function skipped(string $reason, ?array $result = null): array
    {
        return ['message' => $reason, 'result' => $result, 'delivered' => false, 'failed' => false];
    }
}
