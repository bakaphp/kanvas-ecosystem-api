<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Kanvas\Connectors\Twilio\Enums\MessageStatusEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Events\MessageDeliveryStatusEvent;
use Kanvas\Social\Messages\Models\Message;

/**
 * Mirrors the Twilio delivery state into the message body under `delivery`, so a client reading the
 * message sees it without the custom fields, and pushes the outcome to the message's channels once it
 * is known (delivered, or failed with its error).
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
        MessageStatusEnum $status,
        ?string $messageSid = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        $payload = $this->message->getMessage();

        $delivery = [
            'provider' => 'twilio',
            'status' => $status->value,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'message_sid' => $messageSid ?? ($payload['delivery']['message_sid'] ?? null),
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
