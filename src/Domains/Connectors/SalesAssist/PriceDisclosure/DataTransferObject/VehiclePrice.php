<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\DataTransferObject;

use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureFeeEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Spatie\LaravelData\Data;

/**
 * The statutory price concepts the disclosure renders from: the CA CARS total is the MSRP, the
 * pre-rebate selling price is the MSRP plus the dealer-required fees, and the FTC actual price is the
 * advertised (internet) price plus those same fees.
 */
class VehiclePrice extends Data
{
    /**
     * @param array<int, array{type: string, amount: float}> $mandatoryDealerFees
     */
    public function __construct(
        public readonly Variants $variant,
        public readonly string $vehicleKey,
        public readonly string $stockNumber,
        public readonly float $caCarsTotalPrice,
        public readonly float $preRebateSellingPrice,
        public readonly float $ftcActualPrice,
        public readonly array $mandatoryDealerFees,
        public readonly string $sourceSystem,
        public readonly ?string $sourceVersion = null,
        public readonly string $currency = 'USD',
    ) {
    }

    public function fee(PriceDisclosureFeeEnum $fee): float
    {
        foreach ($this->mandatoryDealerFees as $dealerFee) {
            if (($dealerFee['type'] ?? null) === $fee->value) {
                return (float) ($dealerFee['amount'] ?? 0);
            }
        }

        return 0.0;
    }

    public function mandatoryDealerFeesTotal(): float
    {
        return array_sum(array_map(
            fn (array $fee): float => (float) ($fee['amount'] ?? 0),
            $this->mandatoryDealerFees
        ));
    }
}
