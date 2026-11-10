<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;

class BuildDhlRatesPayloadAction
{
    public const string PLANNED_TIME_FORMAT = 'Y-m-d\TH:i:s \G\M\TP';

    public function __construct(
        protected CompanyInterface $company,
        protected array $parcels,
        protected array $destination,
        protected ?Carbon $shipDate = null,
    ) {
    }

    public function execute(): array
    {
        $origin = (array) $this->company->get(ConfigurationEnum::ORIGIN->value);

        return [
            'customerDetails' => [
                'shipperDetails' => $this->addressPayload($origin),
                'receiverDetails' => $this->addressPayload($this->destination),
            ],
            'accounts' => [[
                'typeCode' => 'shipper',
                'number' => (string) $this->company->get(ConfigurationEnum::ACCOUNT_NUMBER->value),
            ]],
            'plannedShippingDateAndTime' => $this->plannedShippingDate()->format(self::PLANNED_TIME_FORMAT),
            'unitOfMeasurement' => 'metric',
            'isCustomsDeclarable' => false,
            'packages' => array_map(fn (array $parcel): array => $this->packagePayload($parcel), $this->parcels),
            'valueAddedServices' => [[
                'serviceCode' => $this->signatureService(),
            ]],
        ];
    }

    public function signatureService(): string
    {
        return (string) ($this->company->get(ConfigurationEnum::SIGNATURE_SERVICE->value) ?: ConfigurationEnum::DEFAULT_SIGNATURE_SERVICE);
    }

    private function plannedShippingDate(): Carbon
    {
        return $this->shipDate ?? Carbon::now()->addWeekday()->setTime(10, 0);
    }

    private function addressPayload(array $address): array
    {
        return [
            'postalCode' => (string) ($address['postal_code'] ?? ''),
            'cityName' => (string) ($address['city'] ?? ''),
            'countryCode' => strtoupper((string) ($address['country_code'] ?? '')),
        ];
    }

    private function packagePayload(array $parcel): array
    {
        return [
            'weight' => (float) $parcel['weight'],
            'dimensions' => [
                'length' => (float) $parcel['length'],
                'width' => (float) $parcel['width'],
                'height' => (float) $parcel['height'],
            ],
        ];
    }
}
