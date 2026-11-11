<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Baka\Support\Str;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Social\Messages\Models\Message;

/**
 * Points a delivery filed at send time at the Message its sender wrote afterwards (a lead send stores
 * its history once the provider answered), and writes the state the delivery already reached onto it.
 *
 * The send result names the delivery by `provider` + `provider_message_id`, or by `attempt_uuid` when
 * the provider refused the send and never issued an id. A delivery already linked is left alone.
 */
final class LinkDeliveryMessageAction
{
    public function __construct(
        private readonly Message $message,
    ) {
    }

    public function execute(array $sendResult): ?Delivery
    {
        $delivery = $this->find($sendResult);

        if (! $delivery instanceof Delivery || $delivery->message_id !== null) {
            return $delivery;
        }

        $delivery->forceFill(['message_id' => $this->message->getId()])->saveOrFail();

        new RecordDeliveryOnMessageAction($delivery)->execute();

        return $delivery;
    }

    private function find(array $sendResult): ?Delivery
    {
        $provider = Str::trimToNull((string) ($sendResult['provider'] ?? ''));
        $externalId = Str::trimToNull((string) ($sendResult['provider_message_id'] ?? ''));
        $uuid = Str::trimToNull((string) ($sendResult['attempt_uuid'] ?? ''));

        if ($provider === null || ($externalId === null && $uuid === null)) {
            return null;
        }

        return Delivery::query()
            ->fromApp($this->message->app)
            ->fromCompany($this->message->company)
            ->where('provider', $provider)
            ->when(
                $externalId !== null,
                fn ($query) => $query->where('external_id', $externalId),
                fn ($query) => $query->where('uuid', $uuid),
            )
            ->first();
    }
}
