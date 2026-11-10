<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Locations\Models\Countries;
use Kanvas\Souk\Orders\Models\Order;

class Consignee
{
    public function __construct(
        public readonly ?string $fullName,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly ?string $city,
        public readonly ?string $postalCode,
        public readonly ?string $countryCode,
        public readonly ?string $state = null,
    ) {
    }

    public static function fromOrder(Order $order): self
    {
        $address = $order->shippingAddress;
        $country = $address?->countries_id ? Countries::find($address->countries_id) : null;

        return new self(
            fullName: Str::trimToNull($order->people?->name),
            phone: Str::trimToNull($order->getPhone()),
            email: Str::trimToNull($order->getEmail()),
            addressLine1: Str::trimToNull($address?->address),
            addressLine2: Str::trimToNull($address?->address_2),
            city: Str::trimToNull($address?->city),
            postalCode: Str::trimToNull($address?->zip),
            countryCode: $country === null ? null : strtoupper($country->code),
            state: Str::trimToNull($address?->state),
        );
    }

    public function missingFields(): array
    {
        $required = [
            'name' => $this->fullName,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->addressLine1,
            'city' => $this->city,
            'country' => $this->countryCode,
        ];

        return array_keys(array_filter($required, fn (?string $value): bool => $value === null));
    }

    public function toPayload(): array
    {
        return [
            'postalAddress' => [
                'postalCode' => (string) $this->postalCode,
                ...array_filter([
                    'cityName' => $this->city,
                    'countryCode' => $this->countryCode,
                    'provinceName' => $this->state,
                    'addressLine1' => $this->addressLine1,
                    'addressLine2' => $this->addressLine2,
                ]),
            ],
            'contactInformation' => [
                'email' => $this->email,
                'phone' => $this->phone,
                'companyName' => $this->fullName,
                'fullName' => $this->fullName,
            ],
        ];
    }
}
