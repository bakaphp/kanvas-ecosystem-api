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
 * so the same event is stored once and the delivery's status only ever moves forward.
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
        try {
            return DB::connection('workflow')->transaction(fn (): bool => $this->record());
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function record(): bool
    {
        $delivery = Delivery::query()->lockForUpdate()->findOrFail($this->delivery->getKey());

        $event = DeliveryEvent::firstOrCreate(
            ['event_key' => $this->eventKey()],
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
                'error_code' => $this->status === DeliveryStatusEnum::FAILED ? $this->errorCode : $delivery->error_code,
                'error' => $this->status === DeliveryStatusEnum::FAILED ? $this->error : $delivery->error,
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
     * WhatsApp can still report `read`.
     */
    private function isTerminal(Delivery $delivery): bool
    {
        if ($delivery->provider === null) {
            return false;
        }

        $terminalEvents = app(ProviderRegistry::class)->get($delivery->provider)::eventRetention()->terminalEvents;

        return in_array($this->providerStatus, $terminalEvents, true);
    }

    private function eventKey(): string
    {
        return hash('sha256', implode('|', [
            $this->delivery->getKey(),
            $this->providerStatus,
            $this->occurredAt?->getTimestamp() ?? '',
        ]));
    }
}
