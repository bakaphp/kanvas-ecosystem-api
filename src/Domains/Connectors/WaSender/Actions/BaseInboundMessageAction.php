<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Connectors\WaSender\Jobs\ProcessGroupBurstJob;
use Kanvas\Connectors\WaSender\Services\GroupBurstService;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * Shared by the two agent-facing filing paths (group room, assistant DM). Both chain their
 * messages onto a burst, re-arm the debounce that closes it, and attach media under the same
 * policy; only the timing windows and the payload shape differ.
 *
 * The lead path deliberately does not extend this — it has no burst at all.
 */
abstract class BaseInboundMessageAction
{
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

    /**
     * Re-arms the debounce on every part, so only the last message in a burst survives to close it.
     */
    protected function armBurstClose(Message $head): void
    {
        $token = Str::uuid()->toString();
        $delaySeconds = $this->closeIdleSeconds() + $this->jitterSeconds();

        Cache::put(
            ProcessGroupBurstJob::cacheKey($head->getId()),
            $token,
            BurstConfigEnum::BURST_MAX_SECONDS->getInt($this->receiver) + $delaySeconds
        );

        ProcessGroupBurstJob::dispatch(
            $this->receiver->app,
            $this->receiver,
            $this->channel,
            $head->getId(),
            $token
        )->delay(now()->addSeconds($delaySeconds));
    }

    private function jitterSeconds(): int
    {
        $jitter = BurstConfigEnum::BURST_JITTER_SECONDS->getInt($this->receiver);

        return $jitter > 0 ? random_int(0, $jitter) : 0;
    }

    /**
     * Serialised per channel: chaining is a read-then-write and deliveries arrive as parallel
     * jobs, so unserialised each part of an album looks for a sibling before the others land and
     * every one of them opens its own burst.
     */
    protected function attachToBurst(Message $message): ?Message
    {
        try {
            return Cache::lock('wasender:burst-chain:' . $this->channel->getId(), 10)
                ->block(5, fn (): ?Message => $this->chainOntoHead($message));
        } catch (LockTimeoutException) {
            // Degrade to an unchained message rather than failing the delivery. A split burst is
            // worse output; a dropped webhook is lost data.
            return null;
        }
    }

    private function chainOntoHead(Message $message): ?Message
    {
        $head = new GroupBurstService(
            $this->channel,
            $this->chainIdleSeconds(),
            BurstConfigEnum::BURST_MAX_SECONDS->getInt($this->receiver),
        )->resolveHead($message, $this->inbound);

        if ($head === null) {
            return null;
        }

        $message->parent_id = $head->getId();
        $message->parent_unique_id = $head->uuid;
        $message->saveOrFail();

        return $head;
    }

    /**
     * Chaining runs before the media download: the download takes seconds, and a message left
     * unparented that long is adopted as head by the next part of the burst.
     */
    protected function fileIntoBurst(Message $message, MessageTypeEnum $messageType): void
    {
        $head = $this->attachToBurst($message);

        $this->attachMedia($message, $messageType);
        $this->armBurstClose($head ?? $message);
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
