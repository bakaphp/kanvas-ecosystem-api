<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

class Transaction extends Data
{
    public function __construct(
        public readonly int $transactionId,
        public readonly float $amount,
    ) {
    }

    public static function viaRequest(array $orderInput): self
    {
        $transactionData = Arr::get($orderInput, 'transaction', []);

        return new self(
            $transactionData['transactionId'],
            $transactionData['amount']
        );
    }
}
