<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\Actions\Concerns\DownloadsMessageMedia;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Services\ConversationChannelService;
use Kanvas\Connectors\WaSender\Services\GroupSpeakerService;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Concerns\ChainsInboundBursts;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * Shared by the two agent-facing filing paths (group room, assistant DM). Both file the message
 * against the Channel, chain it onto a burst, re-arm the debounce that closes it, and attach media
 * under the same policy; only who the speaker is, the timing windows and a few payload keys differ.
 *
 * The debounce itself is `Social\Messages\Concerns\ChainsInboundBursts` — the same one SMS uses.
 * What stays here is the WhatsApp half: the album/speaker correlation keys, the mention-shortened
 * close window, and the media policy.
 *
 * The lead path deliberately does not extend this — it has no burst at all.
 */
abstract class BaseInboundMessageAction
{
    use ChainsInboundBursts;
    use DownloadsMessageMedia;

    public function __construct(
        protected readonly ReceiverWebhook $receiver,
        protected readonly Channel $channel,
        protected readonly InboundMessage $inbound,
        protected readonly array $messageData,
    ) {
    }

    /**
     * Null when the payload carries nothing to file: a forwarded ad card has neither text nor
     * media. Checked before the speaker is resolved, so it leaves no People row behind.
     */
    public function execute(): ?Message
    {
        $content = $this->messageData['message'] ?? [];
        $messageType = MessageTypeEnum::getMessageType($content);
        $text = MessageTypeEnum::extractText($content);

        if ($text === null && MessageTypeEnum::mediaKey($content) === null) {
            return null;
        }

        $people = $this->speaker();
        $message = $this->file($messageType, $this->storedText($text, $people), $people);

        // `messages.upsert` echoes outgoing messages back; arming a burst on one would have the
        // agent answer itself, forever. It is still filed, so history stays complete.
        if ($this->inbound->isFromMe) {
            $this->attachMedia($message, $messageType);

            return $message;
        }

        $this->fileIntoBurstWithMedia($message, $messageType);

        return $message;
    }

    protected function speaker(): ?People
    {
        return new GroupSpeakerService($this->receiver, $this->inbound)->resolve();
    }

    protected function storedText(?string $text, ?People $speaker): ?string
    {
        return $text;
    }

    /**
     * What this conversation shape adds to the stored message.
     *
     * @return array<string, mixed>
     */
    protected function shapePayload(): array
    {
        return [];
    }

    private function file(MessageTypeEnum $messageType, ?string $text, ?People $speaker): Message
    {
        $app = $this->receiver->app;

        $message = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: MessageTypeService::getOrCreate($app, $messageType->value),
                message: [
                    ...AiChatMessagePayload::from([
                        'content' => $text,
                        'from_me' => $this->inbound->isFromMe,
                        'from_ia' => false,
                        'raw_data' => $this->messageData,
                        'message_id' => $this->inbound->messageId,
                        'chat_jid' => $this->inbound->conversationJid,
                    ])->toArray(),
                    'conversation_type' => $this->inbound->conversationType->value,
                    'sender_jid' => $this->inbound->senderJid,
                    'sender_lid' => $this->inbound->senderLid,
                    'sender_phone' => $this->inbound->senderPhone,
                    'sender_name' => $this->inbound->pushName,
                    'sender_identity' => $this->inbound->senderIdentity(),
                    'album_id' => $this->inbound->albumId,
                    'quoted_message_id' => $this->inbound->quotedMessageId,
                    'quoted_participant' => $this->inbound->quotedParticipant,
                    ...$this->shapePayload(),
                ],
                is_public: 1,
                slug: ConversationChannelService::messageSlug(
                    $this->inbound->messageId,
                    $this->inbound->conversationJid
                ),
                tags: [$this->inbound->conversationJid],
            ),
            SystemModulesRepository::getByModelName(Channel::class, $app),
            $this->channel->getId(),
        )->execute();

        if ($speaker !== null) {
            $message->people_id = $speaker->getId();
            $message->saveOrFail();
        }

        $this->channel->addMessage($message);

        return $message;
    }

    /**
     * How long this message extends the burst it belongs to.
     */
    abstract protected function chainIdleSeconds(): int;

    /**
     * How long to wait for silence before closing the burst and running the agent.
     */
    abstract protected function closeIdleSeconds(): int;

    protected function burstPolicy(): BurstPolicy
    {
        // Most specific first. The speaker key binds an album to the caption it illustrates — they
        // share a sender but not an album id — while the album key lets a straggling part rejoin
        // its siblings after the idle window would have closed them.
        $correlationKeys = array_values(array_filter([
            $this->inbound->albumId !== null ? 'album:' . $this->inbound->albumId : null,
            $this->inbound->senderIdentity() !== null ? 'speaker:' . $this->inbound->senderIdentity() : null,
        ]));

        return new BurstPolicy(
            correlationKeys: $correlationKeys,
            chainIdleSeconds: $this->chainIdleSeconds(),
            closeIdleSeconds: $this->closeIdleSeconds(),
            maxSeconds: BurstConfigEnum::BURST_MAX_SECONDS->getInt($this->receiver),
            jitterSeconds: BurstConfigEnum::BURST_JITTER_SECONDS->getInt($this->receiver),
        );
    }

    /**
     * Chaining runs before the media download: the download takes seconds, and a message left
     * unparented that long is adopted as head by the next part of the burst. That ordering is why
     * this calls the two halves itself instead of the trait's `fileIntoBurst()`.
     */
    protected function fileIntoBurstWithMedia(Message $message, MessageTypeEnum $messageType): void
    {
        $policy = $this->burstPolicy();
        $head = $this->attachToBurst($this->channel, $message, $policy);

        $this->attachMedia($message, $messageType);

        $this->armBurstClose(
            $this->receiver->app,
            $this->channel,
            $head ?? $message,
            $policy,
            WaSenderBurstHandler::class,
            ['receiver_id' => $this->receiver->getId()]
        );
    }

    protected function attachMedia(Message $message, MessageTypeEnum $messageType): void
    {
        if (! $messageType->isDocumentType()) {
            return;
        }

        // Before the `media_types` gate: the poster is the agent's only view of a video.
        $this->attachVideoPoster($message, $messageType);

        if (! in_array(
            $messageType->value,
            BurstConfigEnum::MEDIA_TYPES->getList($this->receiver),
            true
        )) {
            $message->addTag('media-not-processed');

            return;
        }

        $this->downloadMedia($message);
    }

    /**
     * No model takes video: `AttachmentDescriptionService::nativeKind()` returns null for `video/*`
     * and the attachment is dropped before the prompt. WhatsApp ships a poster frame inside the
     * payload itself, so storing that as an image is the whole of "the agent saw the video" — and
     * it costs no extra fetch, unlike the 4MB clip beside it.
     */
    private function attachVideoPoster(Message $message, MessageTypeEnum $messageType): void
    {
        if ($messageType !== MessageTypeEnum::VIDEO) {
            return;
        }

        $thumbnail = MessageTypeEnum::mediaNode((array) ($this->messageData['message'] ?? []))['jpegThumbnail'] ?? null;

        if (! is_string($thumbnail) || $thumbnail === '') {
            return;
        }

        try {
            $poster = new FilesystemServices($this->receiver->app, $this->receiver->company)
                ->createFileSystemFromBase64(
                    $thumbnail,
                    'video-poster-' . $this->inbound->messageId . '.jpg',
                    $this->receiver->user
                );

            $message->addFile($poster, 'video-poster');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
