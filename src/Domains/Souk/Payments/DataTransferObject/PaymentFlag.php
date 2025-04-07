<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

class PaymentFlag extends Data
{
    public function __construct(
        public readonly bool $flag
    ) {
    }

    public static function viaRequest(array $orderInput): self
    {
        $paymentFlagData = Arr::get($orderInput, 'paymentFlag');


        return new self(
            $paymentFlagData
        );
    }
}
