<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use Kanvas\Souk\Taxes\Enums\TaxTreatmentEnum;
use Spatie\LaravelData\Data;

class TaxResult extends Data
{
    public function __construct(
        public readonly TaxTreatmentEnum $treatment,
        public readonly string $totalTax,
        public readonly string $totalTaxable,
        public readonly array $components,
        public readonly ?string $providerTransactionId = null,
        public readonly ?string $failureReason = null,
    ) {
    }

    public static function blocked(string $reason): self
    {
        return new self(
            treatment: TaxTreatmentEnum::NOT_COLLECTING,
            totalTax: '0',
            totalTaxable: '0',
            components: [],
            failureReason: $reason,
        );
    }

    public static function nullResult(): self
    {
        return new self(
            treatment: TaxTreatmentEnum::NO_BASE,
            totalTax: '0',
            totalTaxable: '0',
            components: [],
        );
    }
}
