<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class VehicleFinancing extends Data
{
    public function __construct(
        public bool $available,
        public string $termMonths,
        public string $downPayment,
        public string $amountToFinance,
        public string $monthlyPayment
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            available: $data['FinanzFound'] ?? false,
            termMonths: $data['TermMonths'] ?? '',
            downPayment: $data['DownPayment'] ?? '',
            amountToFinance: $data['AmountToFinance'] ?? '',
            monthlyPayment: $data['MonthlyPayment'] ?? ''
        );
    }
}
