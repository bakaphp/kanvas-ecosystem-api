<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Dhl\DataTransferObject\Consignee;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Shipping\Actions\BuildShipmentRequestAction;
use Kanvas\Souk\Shipping\Enums\ConfigurationEnum as ShippingConfigurationEnum;

class BuildDhlShipmentPayloadAction
{
    public function __construct(
        protected Order $order,
        protected Consignee $consignee,
        protected array $quoteMeta,
        protected bool $nextBusinessDay = false,
    ) {
    }

    public function execute(): array
    {
        $company = $this->order->company;
        $origin = (array) $company->get(ConfigurationEnum::ORIGIN->value);
        $pickupRequested = (bool) $company->get(ConfigurationEnum::REQUEST_PICKUP->value);

        return array_filter([
            'plannedShippingDateAndTime' => $this->plannedShippingDate()->format(BuildDhlRatesPayloadAction::PLANNED_TIME_FORMAT),
            'pickup' => $this->pickupPayload($pickupRequested),
            'productCode' => $this->quoteMeta['product_code'],
            'localProductCode' => $this->quoteMeta['local_product_code'] ?? null,
            'accounts' => [[
                'typeCode' => 'shipper',
                'number' => (string) $company->get(ConfigurationEnum::ACCOUNT_NUMBER->value),
            ]],
            'customerDetails' => [
                'shipperDetails' => $this->shipperPayload($origin),
                'receiverDetails' => $this->consignee->toPayload(),
            ],
            'content' => [
                'packages' => [$this->packagePayload()],
                'isCustomsDeclarable' => false,
                'description' => ConfigurationEnum::CONTENT_DESCRIPTION->valueFor($company),
                'unitOfMeasurement' => 'metric',
            ],
            'valueAddedServices' => [['serviceCode' => $this->quoteMeta['signature_code']]],
            'customerReferences' => [['typeCode' => 'CU', 'value' => (string) $this->order->order_number]],
            'outputImageProperties' => [
                'printerDPI' => 300,
                'encodingFormat' => 'pdf',
                'imageOptions' => [[
                    'typeCode' => 'label',
                    'templateName' => ConfigurationEnum::LABEL_TEMPLATE->valueFor($company),
                ]],
            ],
        ]);
    }

    private function plannedShippingDate(): Carbon
    {
        $now = BuildDhlRatesPayloadAction::localNow($this->order->company);
        $planned = $now->copy()->addHour();
        $closesAt = $planned->copy()->setTimeFromTimeString(ConfigurationEnum::PICKUP_CLOSE_TIME->valueFor($this->order->company));

        if ($this->nextBusinessDay || $planned->isWeekend() || $planned->gt($closesAt)) {
            return $now->addWeekday()->setTime(9, 0);
        }

        return $planned;
    }

    private function pickupPayload(bool $requested): array
    {
        if (! $requested) {
            return ['isRequested' => false];
        }

        return [
            'isRequested' => true,
            'closeTime' => ConfigurationEnum::PICKUP_CLOSE_TIME->valueFor($this->order->company),
            'location' => 'reception',
        ];
    }

    private function shipperPayload(array $origin): array
    {
        return [
            'postalAddress' => [
                'postalCode' => (string) ($origin['postal_code'] ?? ''),
                'cityName' => $origin['city'],
                'countryCode' => strtoupper($origin['country_code']),
                'addressLine1' => $origin['address_line1'],
            ],
            'contactInformation' => [
                'email' => $origin['email'],
                'phone' => $origin['phone'],
                'companyName' => $origin['company_name'],
                'fullName' => $origin['contact_name'],
            ],
        ];
    }

    private function packagePayload(): array
    {
        $box = (array) $this->order->app->get(ShippingConfigurationEnum::DEFAULT_BOX_CM->value);

        return [
            'weight' => $this->orderWeight(),
            'dimensions' => [
                'length' => (float) $box['length'],
                'width' => (float) $box['width'],
                'height' => (float) $box['height'],
            ],
        ];
    }

    private function orderWeight(): float
    {
        $weight = (float) $this->order->items->sum(
            fn ($item): float => (float) $item->variant?->weight * (float) $item->quantity
        );

        return $weight > 0 ? round($weight, 3) : BuildShipmentRequestAction::MISSING_WEIGHT;
    }
}
