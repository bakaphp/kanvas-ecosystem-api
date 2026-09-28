<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Concerns\ChainsInboundBursts;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * Shared by the two agent-facing filing paths (group room, assistant DM). Both chain their
 * messages onto a burst, re-arm the debounce that closes it, and attach media under the same
 * policy; only the timing windows and the payload shape differ.
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

    public function __construct(
        protected readonly ReceiverWebhook $receiver,
        protected readonly Channel $channel,
        protected readonly InboundMessage $inbound,
        protected readonly array $messageData,
    ) {
    }

    abstract public function execute(): ?Message;

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
        if (! MessageTypeEnum::isDocumentType($messageType->value)) {
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

        try {
            new DownloadMessageFileAction($this->channel, $message)->execute();
        } catch (Throwable $e) {
            $this->recordMediaFailure($message, $e);
        }
    }

    /**
     * A refusal is the provider answering, not faulting: a 39MB clip exceeds WaSender's 25MB decrypt
     * limit and no retry changes that, so reporting it only floods Sentry (KANVAS-ECOSYSTEM-68N).
     * A missing api key, a network fault or storage still is a fault, and still reports.
     */
    private function recordMediaFailure(Message $message, Throwable $e): void
    {
        $message->addTag('media-not-downloaded');
        $message->set('media_download_error', mb_substr($e->getMessage(), 0, 500));

        if (! $e instanceof WaSenderRefusedException) {
            report($e);
        }
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
