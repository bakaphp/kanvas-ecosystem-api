<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Contracts\DeliveryStatus;
use Kanvas\Social\Messages\Events\MessageDeliveryStatusEvent;
use Kanvas\Social\Messages\Models\Message;

/**
 * Mirrors the provider's delivery state into the message body under `delivery`, so a client reading the
 * message sees it without the custom fields, and pushes the outcome to the message's channels once it
 * is known (delivered, or failed with its error).
 *
 * `message_sid` holds the provider's own message id for every provider (a Twilio SID, a Mailgun
 * Message-Id): clients already read that key for SMS.
 *
 * Saved quietly: a status callback is bookkeeping, and a normal save fires the message's UPDATED
 * workflow rules once per callback (queued, sent, delivered...).
 */
class RecordDeliveryOnMessageAction
{
    public function __construct(
        private readonly Message $message,
    ) {
    }

    public function execute(
        DeliveryStatus $status,
        ?string $providerMessageId = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        $payload = $this->message->getMessage();

        $delivery = [
            'provider' => $status->provider(),
            'status' => $status->value,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'message_sid' => $providerMessageId ?? ($payload['delivery']['message_sid'] ?? null),
            'updated_at' => now()->toAtomString(),
        ];

        // A legacy plain-string body has no object to hold the key; rewriting its shape is not ours to do.
        if ($payload !== []) {
            $payload['delivery'] = $delivery;
            $this->message->message = $payload;
            $this->message->saveQuietly();
            $this->message->clearLightHouseCacheJob();
        }

        if ($status->isDeliveryOutcome()) {
            $this->message->channels->each(
                fn (Channel $channel) => MessageDeliveryStatusEvent::dispatch($channel, $this->message, $delivery)
            );
        }
    }
}
