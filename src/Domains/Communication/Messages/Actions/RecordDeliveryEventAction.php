<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Messages\Models\DeliveryEvent;
use Kanvas\Communication\Support\ProviderRegistry;

/**
 * Records what a provider reported about a delivery. Providers repeat and reorder their callbacks,
 * so the same event is stored once and the delivery's status only ever moves forward. A status that
 * did move is written on the message and pushed to its channels.
 */
final class RecordDeliveryEventAction
{
    /**
     * @param string $providerStatus the provider's own word for it, kept beside our status
     */
    public function __construct(
        private readonly Delivery $delivery,
        private readonly DeliveryStatusEnum $status,
        private readonly string $providerStatus,
        private readonly array $payload = [],
        private readonly ?CarbonInterface $occurredAt = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $error = null
    ) {
    }

    /**
     * @return bool false when this exact event was already recorded
     */
    public function execute(): bool
    {
        $statusBefore = $this->delivery->status;

        try {
            $recorded = DB::connection('workflow')->transaction(fn (): bool => $this->record());
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        // After the commit, so a client told about the new status reads it back.
        if ($recorded && $this->delivery->status !== $statusBefore) {
            new RecordDeliveryOnMessageAction($this->delivery)->execute();
        }

        return $recorded;
    }

    private function record(): bool
    {
        $delivery = Delivery::query()->lockForUpdate()->findOrFail($this->delivery->getKey());

        $event = DeliveryEvent::firstOrCreate(
            ['event_key' => $this->eventKey($delivery)],
            [
                'apps_id' => $delivery->apps_id,
                'companies_id' => $delivery->companies_id,
                'delivery_id' => $delivery->getId(),
                'provider_status' => $this->providerStatus,
                'error_code' => $this->errorCode,
                'error' => $this->error,
                'payload' => $this->payload,
                'occurred_at' => $this->occurredAt,
            ]
        );

        if (! $event->wasRecentlyCreated) {
            return false;
        }

        $changes = ['last_event_at' => now()];

        if ($delivery->status->canAdvanceTo($this->status)) {
            $changes += [
                'status' => $this->status,
                // A delivery whose outcome was lost and then reported keeps no trace of the loss.
                'error_code' => $this->status === DeliveryStatusEnum::FAILED ? $this->errorCode : null,
                'error' => $this->status === DeliveryStatusEnum::FAILED ? $this->error : null,
            ];
        }

        if ($delivery->events_closed_at === null && $this->isTerminal($delivery)) {
            $changes['events_closed_at'] = now();
        }

        $delivery->forceFill($changes)->saveOrFail();
        $this->delivery->refresh();

        return true;
    }

    /**
     * Terminal is the provider's call, not ours: after `delivered` Twilio says nothing more, while
     * WhatsApp can still report `read`. A provider that is no longer registered names no terminal
     * events, so this never closes its deliveries.
     */
    private function isTerminal(Delivery $delivery): bool
    {
        $provider = $delivery->provider !== null ? new ProviderRegistry()->all()[$delivery->provider] ?? null : null;

        if ($provider === null) {
            return false;
        }

        return in_array($this->providerStatus, $provider::eventRetention()->terminalEvents, true);
    }

    /**
     * The provider's id is part of the key because a retried delivery is the same row with a new
     * one: without it the second attempt's `failed` reads as the first one's, repeated, and the
     * delivery stays accepted. Twilio and WaSender send no event time to tell them apart.
     */
    private function eventKey(Delivery $delivery): string
    {
        return hash('sha256', implode('|', [
            $delivery->getKey(),
            $delivery->external_id ?? '',
            $this->providerStatus,
            $this->occurredAt?->getTimestamp() ?? '',
        ]));
    }
}
