<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Override;

class ProcessCommentAction extends BaseRespondIOAction
{
    #[Override]
    public function execute(): array
    {
        /** @var string $text */
        $text = $this->payload['text'] ?? '';
        /** @var array<int, array<string, mixed>> $attachments */
        $attachments = $this->payload['attachments'] ?? [];
        /** @var array<int, string> $mentionedEmails */
        $mentionedEmails = $this->payload['mentionedUserEmails'] ?? [];
        /** @var string $eventId */
        $eventId = $this->payload['event_id'] ?? Str::uuid()->toString();

        $identifier = $this->contactIdentifier();

        if ($identifier === null) {
            return ['error' => 'Missing contact identifier'];
        }

        $channel = $this->resolveChannel();

        if ($channel === null) {
            return ['error' => 'No channel found for contact', 'identifier' => $identifier];
        }

        $lead = $this->resolveLead($channel);

        if ($lead === null) {
            return ['error' => 'No lead found for channel', 'channel_id' => $channel->getId()];
        }

        $notesChannel = $this->notesChannelOf($lead);

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

        $messageInput = new MessageInput(
            app: $this->receiver->app,
            company: $this->receiver->company,
            user: $this->receiver->user,
            type: MessageTypeService::getOrCreate($this->receiver->app, 'respondio-comment'),
            message: [
                'content' => $noteContent,
                'raw_data' => $this->payload,
                'source' => 'respondio-comment',
                'is_internal' => true,
            ],
            is_public: 0,
            slug: 'respondio-comment-' . Str::slug($eventId),
            tags: ['respondio', 'internal-note']
        );

        $message = new CreateMessageAction($messageInput)->execute();
        $message->addEntity($lead);
        // History is loaded by person as well as by lead (Neuron's EntityRollupMessageStore).
        if ($lead->people !== null) {
            $message->addEntity($lead->people);
        }

        $notesChannel->addMessage($message);

        return [
            'message_id' => $message->getId(),
            'notes_channel_id' => $notesChannel->getId(),
            'lead_id' => $lead->getId(),
            'text' => $text,
        ];
    }
}
