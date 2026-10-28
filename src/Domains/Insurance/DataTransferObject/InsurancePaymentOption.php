<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Enums\InsurancePaymentTermEnum;

class InsurancePaymentOption
{
    public function __construct(
        public readonly InsurancePaymentTermEnum $term,
        public readonly float $amount,
        public readonly ?float $premium = null,
        public readonly ?float $tax = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'term' => $this->term->value,
            'installments' => $this->term->installments(),
            'amount' => $this->amount,
            'premium' => $this->premium,
            'tax' => $this->tax,
        ];
    }
}
