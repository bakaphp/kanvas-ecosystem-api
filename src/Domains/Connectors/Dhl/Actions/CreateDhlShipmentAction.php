<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Actions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Dhl\Client;
use Kanvas\Connectors\Dhl\DataTransferObject\Consignee;
use Kanvas\Connectors\Dhl\Enums\BookingStateEnum;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Connectors\Dhl\Exceptions\DhlBookingException;
use Kanvas\Connectors\Dhl\Providers\DhlShippingProvider;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Shipping\Enums\ShippingConditionEnum;

class CreateDhlShipmentAction
{
    public const string CUSTOMER_TEMPLATE = 'user-order-dispatched';
    public const string LABEL_FIELD = 'dhl-label';
    public const string PAST_PICKUP_CUTOFF = '5001:';

    public function __construct(protected Order $order)
    {
    }

    public function execute(): array
    {
        $shipping = (array) ($this->order->metadata['shipping'] ?? []);

        if (($shipping[ShippingConditionEnum::PROVIDER->value] ?? null) !== DhlShippingProvider::NAME) {
            return $this->skipped('The order was not quoted with DHL');
        }

        $state = $this->bookingState();

        if ($state === BookingStateEnum::PENDING && empty($this->order->tracking_client_id)) {
            throw DhlBookingException::pendingWithoutTracking();
        }

        if ($state !== null) {
            return $this->skipped("Booking state is already {$state->value}");
        }

        $meta = $this->quoteMeta($shipping);
        $consignee = Consignee::fromOrder($this->order);

        if ($consignee->missingFields() !== []) {
            throw DhlBookingException::missingConsigneeData($consignee->missingFields());
        }

        $response = $this->book($consignee, $meta);

        $this->persistBooking($response);
        $this->attachLabel($response);
        $this->moveToDispatched();
        $this->notifyCustomer($response);

        return [
            'order' => $this->order->getId(),
            'status' => 'success',
            'tracking_number' => $response['shipmentTrackingNumber'],
        ];
    }

    private function book(Consignee $consignee, array $meta): array
    {
        $this->order->set(CustomFieldEnum::BOOKING_STATE->value, BookingStateEnum::PENDING->value);

        try {
            return $this->sendShipment($consignee, $meta);
        } catch (RequestException $exception) {
            if (! $this->isPastPickupCutoff($exception)) {
                $this->failBooking($exception);
            }
        }

        try {
            return $this->sendShipment($consignee, $meta, nextBusinessDay: true);
        } catch (RequestException $exception) {
            $this->failBooking($exception);
        }
    }

    private function sendShipment(Consignee $consignee, array $meta, bool $nextBusinessDay = false): array
    {
        $payload = new BuildDhlShipmentPayloadAction(
            $this->order,
            $consignee,
            $meta,
            $nextBusinessDay
        )->execute();

        return new Client($this->order->company)->createShipment($payload, (string) $this->order->uuid);
    }

    private function isPastPickupCutoff(RequestException $exception): bool
    {
        return $exception->response->clientError()
            && collect((array) $exception->response->json('additionalDetails', []))
                ->contains(fn (mixed $detail): bool => is_string($detail) && str_starts_with($detail, self::PAST_PICKUP_CUTOFF));
    }

    private function failBooking(RequestException $exception): never
    {
        if (! $exception->response->clientError()) {
            throw $exception;
        }

        $this->order->del(CustomFieldEnum::BOOKING_STATE->value);

        $detail = (string) ($exception->response->json('detail') ?? $exception->getMessage());
        $additional = array_filter((array) $exception->response->json('additionalDetails', []), 'is_string');

        throw DhlBookingException::rejected(trim($detail . ' ' . implode('; ', $additional)));
    }

    private function persistBooking(array $response): void
    {
        $this->order->tracking_client_id = (string) $response['shipmentTrackingNumber'];
        $this->order->shipped_date = Carbon::now()->toDateTimeString();
        $this->order->saveQuietly();

        $this->order->set(CustomFieldEnum::DISPATCH_CONFIRMATION_NUMBER->value, $response['dispatchConfirmationNumber'] ?? null);
        $this->order->set(CustomFieldEnum::BOOKING_STATE->value, BookingStateEnum::BOOKED->value);
    }

    private function attachLabel(array $response): void
    {
        $label = collect($response['documents'] ?? [])->firstWhere('typeCode', 'label');

        if ($label === null) {
            return;
        }

        app(AttachDhlDocumentAction::class)->execute(
            $this->order,
            $label['content'],
            "dhl-label-{$response['shipmentTrackingNumber']}.pdf",
            self::LABEL_FIELD
        );
    }

    private function moveToDispatched(): void
    {
        $this->order->transitionToStatus($this->order->company->user, OrderStatusEnum::DISPATCHED->value);
    }

    private function notifyCustomer(array $response): void
    {
        new SendOrderEmailsAction(
            $this->order,
            self::CUSTOMER_TEMPLATE,
            [
                'tracking_number' => $response['shipmentTrackingNumber'],
                'tracking_url' => $response['trackingUrl'] ?? null,
            ],
            ['mail', 'database'],
        )->execute();
    }

    private function quoteMeta(array $shipping): array
    {
        $meta = (array) ($shipping[ShippingConditionEnum::BOOKING_META->value] ?? []);

        if (empty($meta['product_code']) || empty($meta['signature_code'])) {
            throw DhlBookingException::quoteChanged('the order carries no DHL product code');
        }

        $configured = ConfigurationEnum::SIGNATURE_SERVICE->valueFor($this->order->company);

        if ($meta['signature_code'] !== $configured) {
            throw DhlBookingException::quoteChanged("quoted with {$meta['signature_code']}, configured {$configured}");
        }

        return $meta;
    }

    private function bookingState(): ?BookingStateEnum
    {
        $state = $this->order->get(CustomFieldEnum::BOOKING_STATE->value);

        return $state === null ? null : BookingStateEnum::tryFrom((string) $state);
    }

    private function skipped(string $reason): array
    {
        return ['order' => $this->order->getId(), 'status' => 'skipped', 'message' => $reason];
    }
}
