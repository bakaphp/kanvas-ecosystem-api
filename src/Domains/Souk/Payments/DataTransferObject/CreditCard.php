<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

class CreditCard extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $number,
        public readonly int $exp_month,
        public readonly int $exp_year,
        public readonly int $cvv,
        public readonly ?CreditCardBilling $billing = null
    ) {
    }

    public static function viaRequest(array $orderInput): self
    {
        $paymentData = Arr::get($orderInput, 'payment', []);
        $billingData = Arr::get($orderInput, 'billing', []);

        $billing = $billingData ? new CreditCardBilling(
            $billingData['address'],
            $billingData['city'],
            $billingData['state'],
            $billingData['zip'],
            $billingData['country'],
            $billingData['address2'] ?? null
        ) : null;

        return new self(
            $paymentData['name'],
            $paymentData['number'],
            $paymentData['exp_month'],
            $paymentData['exp_year'],
            $paymentData['cvv'],
            $billing
        );
    }
}
