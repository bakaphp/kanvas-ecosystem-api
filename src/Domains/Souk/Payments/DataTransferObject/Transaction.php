<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

class Transaction extends Data
{
    public function __construct(
        public readonly int $transactionId,
    ) {
    }

    public static function viaRequest(array $orderInput): self
    {
        $profileData = Arr::get($orderInput, 'transactionId', []);

        return new self(
            $profileData['transactionId'],
        );
    }
}
