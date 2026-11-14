<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\Actions\LinkDeliveryMessageAction;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\NotifyLeadStakeholdersService;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Intelligence\Triggers\Events\LeadAiTriggeredEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Actions\MarkLeadMessagesAsRespondedAction;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * A message someone on our side filed in a lead's conversation (a person on the team, an AI that
 * runs outside Kanvas) goes to the customer on the medium the conversation is in. Needs no rule.
 *
 * A person writing takes the lead over (the AI steps back); another AI does not. A message that
 * did not leave is noted on the lead and never counts as an answer.
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
     * @return array{message: string, result: array<string, mixed>|null, delivered: bool}
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

        if (! Communication::for($lead->app, $lead->company)->isReady(ChannelTypeEnum::from($medium))) {
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
            LeadAiTriggeredEvent::announce($lead, TriggersEnum::HUMAN_TAKEOVER->value);
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
            $result = new SendMessageToLeadAction($lead)->execute(
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

        new MarkLeadMessagesAsRespondedAction($lead, $this->message)->execute();
        new NotifyLeadStakeholdersService($lead)->onAgentReply($this->message, isHuman: $this->writtenByPerson);

        return [
            'message' => 'Message sent to lead via ' . $medium,
            'result' => $result,
            'delivered' => true,
        ];
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
     * @return array{message: string, result: array<string, mixed>|null, delivered: bool}
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

        return $this->skipped($reason, $result);
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
     * @return array{message: string, result: array<string, mixed>|null, delivered: bool}
     */
    private function skipped(string $reason, ?array $result = null): array
    {
        return ['message' => $reason, 'result' => $result, 'delivered' => false];
    }
}
