<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

class CreditCardBilling extends Data
{
    public function __construct(
        public readonly string $address,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zip,
        public readonly string $country = 'USA',
        public readonly ?string $address2 = null
    ) {
    }

    public static function viaRequest(array $orderInput): ?self
    {
        $billingData = Arr::get($orderInput, 'billing', []);

        $billing = $billingData ? new CreditCardBilling(
            $billingData['address'],
            $billingData['city'],
            $billingData['state'],
            $billingData['zip'],
            $billingData['country'],
            $billingData['address2'] ?? null
        ) : null;

        return $billing;
    }
}
