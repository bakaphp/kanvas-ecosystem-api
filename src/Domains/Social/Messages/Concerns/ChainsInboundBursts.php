<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Concerns;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Jobs\FlushMessageBurstJob;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Services\MessageBurstService;

/**
 * The ingest half of the burst: chain a freshly filed message onto the burst it belongs to, and
 * re-arm the debounce that closes it. Mixed into whatever files the message — a webhook job, an
 * inbound action — because that is the only place that knows the message is complete.
 */
trait ChainsInboundBursts
{
    /**
     * Chain, then arm. Ordering matters when the caller also downloads media: chain first, because
     * the download takes seconds and a message left unparented that long is adopted as head by the
     * next part of the burst. A caller with media to fetch calls the two halves itself.
     */
    protected function fileIntoBurst(
        Apps $app,
        Channel $channel,
        Message $message,
        BurstPolicy $policy,
        string $handlerClass,
        array $params = []
    ): void {
        $head = $this->attachToBurst(
            $channel,
            $message,
            $policy
        );

        $this->armBurstClose(
            $app,
            $channel,
            $head ?? $message,
            $policy,
            $handlerClass,
            $params
        );
    }

    /**
     * Serialised per channel: chaining is a read-then-write and deliveries arrive as parallel jobs,
     * so unserialised each part of a flurry looks for a sibling before the others land and every one
     * of them opens its own burst.
     */
    protected function attachToBurst(Channel $channel, Message $message, BurstPolicy $policy): ?Message
    {
        try {
            return Cache::lock('message-burst-chain:' . $channel->getId(), 10)
                ->block(5, fn (): ?Message => $this->chainOntoHead($channel, $message, $policy));
        } catch (LockTimeoutException) {
            // Degrade to an unchained message rather than failing the delivery. A split burst is
            // worse output; a dropped webhook is lost data.
            return null;
        }
    }

    /**
     * Re-arms the debounce on every part, so only the last message in a burst survives to close it.
     */
    protected function armBurstClose(
        Apps $app,
        Channel $channel,
        Message $head,
        BurstPolicy $policy,
        string $handlerClass,
        array $params = []
    ): void {
        $token = Str::uuid()->toString();
        $delaySeconds = $policy->closeDelaySeconds();

        Cache::put(
            FlushMessageBurstJob::cacheKey($head->getId()),
            $token,
            $policy->tokenTtlSeconds()
        );

        FlushMessageBurstJob::dispatch(
            $app,
            $channel,
            $head->getId(),
            $token,
            $handlerClass,
            $params
        )->delay(now()->addSeconds($delaySeconds));
    }

    private function chainOntoHead(Channel $channel, Message $message, BurstPolicy $policy): ?Message
    {
        $head = new MessageBurstService($channel, $policy)->resolveHead($message);

        if ($head === null) {
            return null;
        }

        $message->parent_id = $head->getId();
        $message->parent_unique_id = $head->uuid;
        $message->saveOrFail();

        // Tagged here rather than by the caller so it can never disagree with parent_id.
        $message->addTag(MessageBurstService::BURST_PART_TAG);

        return $head;
    }
}
