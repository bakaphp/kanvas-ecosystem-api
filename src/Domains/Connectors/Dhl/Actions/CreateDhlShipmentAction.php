<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Actions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Dhl\Client;
use Kanvas\Connectors\Dhl\DataTransferObject\Consignee;
use Kanvas\Connectors\Dhl\Enums\BookingStateEnum;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Connectors\Dhl\Enums\CustomsAttributeEnum;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Connectors\Dhl\Exceptions\DhlBookingException;
use Kanvas\Connectors\Dhl\Providers\DhlShippingProvider;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Shipping\Enums\ShippingConditionEnum;
use Throwable;

class CreateDhlShipmentAction
{
    public const string CUSTOMER_TEMPLATE = 'user-order-dispatched';
    public const string LABEL_FIELD = 'dhl-label';
    public const string INVOICE_FIELD = 'dhl-commercial-invoice';
    public const array DOCUMENT_FIELDS = [
        'label' => self::LABEL_FIELD,
        'invoice' => self::INVOICE_FIELD,
    ];
    public const string PAST_PICKUP_CUTOFF = '5001:';
    public const string LOCK_PREFIX = 'dhl-booking:';
    public const int LOCK_SECONDS = 300;

    public function __construct(protected Order $order)
    {
    }

    public function execute(): array
    {
        $shipping = (array) ($this->order->metadata['shipping'] ?? []);

        if (($shipping[ShippingConditionEnum::PROVIDER->value] ?? null) !== DhlShippingProvider::NAME) {
            return $this->skipped('The order was not quoted with DHL');
        }

        $lock = Cache::lock(self::LOCK_PREFIX . $this->order->uuid, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->skipped('Another booking of this order is running');
        }

        try {
            return $this->bookOnce($shipping);
        } finally {
            $lock->release();
        }
    }

    private function bookOnce(array $shipping): array
    {
        $state = $this->bookingState();

        if ($state === BookingStateEnum::PENDING && empty($this->order->tracking_client_id)) {
            throw DhlBookingException::pendingWithoutTracking();
        }

        if ($state !== null) {
            return $this->skipped("Booking state is already {$state->value}");
        }

        if ($this->order->orderStatus()->value('slug') !== OrderStatusEnum::READY_FOR_DISPATCH->value) {
            return $this->skipped('The order is no longer ready for dispatch');
        }

        $meta = $this->quoteMeta($shipping);
        $consignee = Consignee::fromOrder($this->order);

        if ($consignee->missingFields() !== []) {
            throw DhlBookingException::missingConsigneeData($consignee->missingFields());
        }

        $this->assertCustomsData($consignee);

        $response = $this->book($consignee, $meta);

        $this->persistBooking($response);
        $this->attachDocuments($response);
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

    private function assertCustomsData(Consignee $consignee): void
    {
        if (! BuildDhlRatesPayloadAction::crossesBorder($this->order->company, $consignee->countryCode)) {
            return;
        }

        $missing = [];

        foreach ($this->order->items as $item) {
            $fields = $item->variant === null ? ['variant'] : CustomsAttributeEnum::missingFor($item->variant);

            if ($fields !== []) {
                $missing[(string) $item->product_name] = $fields;
            }
        }

        if ($missing !== []) {
            throw DhlBookingException::missingCustomsData($missing);
        }
    }

    private function attachDocuments(array $response): void
    {
        foreach ($response['documents'] ?? [] as $document) {
            $field = self::DOCUMENT_FIELDS[$document['typeCode'] ?? ''] ?? null;

            if ($field === null || empty($document['content'])) {
                continue;
            }

            try {
                app(AttachDhlDocumentAction::class)->execute(
                    $this->order,
                    $document['content'],
                    "{$field}-{$response['shipmentTrackingNumber']}.pdf",
                    $field
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }
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
