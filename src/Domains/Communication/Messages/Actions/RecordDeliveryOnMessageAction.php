<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Events\MessageDeliveryStatusEvent;
use Kanvas\Social\Messages\Models\Message;

/**
 * Writes a delivery's state into its message body under `delivery`, the one shape a client reads
 * for every channel; `message_sid` holds the provider's id, a name kept from when only SMS had one.
 * The outcome is pushed to the message's channels.
 *
 * Saved quietly: a delivery event is bookkeeping, not an edit that should fire the message's rules.
 */
final class RecordDeliveryOnMessageAction
{
    public function __construct(
        private readonly Delivery $delivery,
    ) {
    }

    public function execute(): void
    {
        $message = $this->delivery->message_id !== null
            ? Message::query()->find($this->delivery->message_id)
            : null;

        if (! $message instanceof Message) {
            return;
        }

        $delivery = [
            'provider' => $this->delivery->provider,
            'status' => $this->delivery->status->value,
            'error_code' => $this->delivery->error_code,
            'error_message' => $this->delivery->error,
            'message_sid' => $this->delivery->external_id,
            'updated_at' => now()->toAtomString(),
        ];

        $payload = $message->getMessage();

        // A legacy plain-string body has no object to hold the key; rewriting its shape is not ours to do.
        if ($payload !== []) {
            $payload['delivery'] = $delivery;
            $message->message = $payload;
            $message->saveQuietly();
            $message->clearLightHouseCacheJob();
        }

        $pushed = [DeliveryStatusEnum::DELIVERED, DeliveryStatusEnum::READ, DeliveryStatusEnum::FAILED];

        if (in_array($this->delivery->status, $pushed, true)) {
            $message->channels->each(
                fn (Channel $channel) => MessageDeliveryStatusEvent::dispatch($channel, $message, $delivery)
            );
        }
    }
}
