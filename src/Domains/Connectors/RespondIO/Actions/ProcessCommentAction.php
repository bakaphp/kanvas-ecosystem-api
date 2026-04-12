<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Kanvas\Connectors\RespondIO\Traits\HasChannelProcessing;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Override;

class ProcessCommentAction extends BaseRespondIOAction
{
    use HasChannelProcessing;

    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $contact */
        $contact = $this->payload['contact'] ?? [];
        /** @var string $text */
        $text = $this->payload['text'] ?? '';
        /** @var array<int, array<string, mixed>> $attachments */
        $attachments = $this->payload['attachments'] ?? [];
        /** @var array<int, string> $mentionedEmails */
        $mentionedEmails = $this->payload['mentionedUserEmails'] ?? [];
        /** @var string $eventId */
        $eventId = $this->payload['event_id'] ?? Str::uuid()->toString();

        $identifier = $this->getContactIdentifier($contact);

        if ($identifier === null) {
            return ['error' => 'Missing contact identifier'];
        }

        $channel = $this->findChannelByIdentifier($this->app, $this->company, $identifier);

        if (! $channel) {
            return ['error' => 'No channel found for contact', 'identifier' => $identifier];
        }

        $lead = $this->findLeadFromChannel($this->app, $this->company, $channel);

        if (! $lead) {
            return ['error' => 'No lead found for channel', 'channel_id' => $channel->getId()];
        }

        $notesChannel = $lead->notes;

        if (! $notesChannel) {
            $notesChannel = new CreateChannelAction(
                new ChannelDto(
                    $this->app,
                    $this->company,
                    $this->user,
                    (string) $lead->getKey(),
                    get_class($lead),
                    ChannelNameEnum::NOTES->value,
                    'AI Notes Channel',
                    Str::uuid()->toString()
                )
            )->execute();
        }

        $noteContent = $text;
        if (! empty($mentionedEmails)) {
            $noteContent .= "\n\nMentioned: " . implode(', ', $mentionedEmails);
        }

        if (! empty($attachments)) {
            $attachmentLinks = [];
            foreach ($attachments as $attachment) {
                /** @var string $fileName */
                $fileName = $attachment['fileName'] ?? 'attachment';
                /** @var string $url */
                $url = $attachment['url'] ?? '';
                $attachmentLinks[] = "{$fileName}: {$url}";
            }
            $noteContent .= "\n\nAttachments:\n" . implode("\n", $attachmentLinks);
        }

        $messageSlug = 'respondio-comment-' . Str::slug($eventId);

        $messageTypeModel = new CreateMessageTypeAction(
            new MessageTypeInput(
                $this->app->getId(),
                0,
                'respondio-comment',
                'respondio-comment',
            )
        )->execute();

        $messageInput = new MessageInput(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            type: $messageTypeModel,
            message: [
                'content' => $noteContent,
                'raw_data' => $this->payload,
                'source' => 'respondio-comment',
                'is_internal' => true,
            ],
            is_public: 0,
            slug: $messageSlug,
            tags: ['respondio', 'internal-note']
        );

        $message = new CreateMessageAction($messageInput)->execute();
        $message->addEntity($lead);

        $notesChannel->addMessage($message);

        return [
            'message_id' => $message->getId(),
            'notes_channel_id' => $notesChannel->getId(),
            'lead_id' => $lead->getId(),
            'text' => $text,
        ];
    }
}
