<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\MessageTypeEnum;
use Kanvas\Connectors\WaSender\Jobs\ProcessGroupBurstJob;
use Kanvas\Connectors\WaSender\Services\GroupBurstService;
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
     * Video is filed so the conversation history is complete, but it is tagged and left
     * undownloaded — nothing sends it to the agent yet.
     */
    protected function attachMedia(Message $message, MessageTypeEnum $messageType): void
    {
        if (! MessageTypeEnum::isDocumentType($messageType->value)) {
            return;
        }

        if (! in_array($messageType->value, BurstConfigEnum::MEDIA_TYPES->getList($this->receiver), true)) {
            $message->addTag('media-not-processed');

            return;
        }

        try {
            new DownloadMessageFileAction($this->channel, $message)->execute();
        } catch (Throwable $e) {
            // One bad attachment must not sink the burst; the text still reaches the agent.
            report($e);
        }
    }
}
