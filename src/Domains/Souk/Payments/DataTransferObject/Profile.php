<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

class Profile extends Data
{
    public function __construct(
        public readonly int $customerProfileId,
        public readonly int $customerPaymentProfileId,
    ) {
    }

    public static function viaRequest(array $orderInput): self
    {
        $profileData = Arr::get($orderInput, 'profile', []);

        return new self(
            $profileData['customerProfileId'],
            $profileData['customerPaymentProfileId'],
        );
    }
}