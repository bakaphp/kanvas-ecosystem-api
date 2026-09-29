<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\DataTransferObject;

use Kanvas\Inventory\Variants\Models\Variants;
use Spatie\LaravelData\Data;

/**
 * The two statutory price concepts the disclosure renders from. They are kept apart even while the
 * channel price feeds both, because the CA CARS total and the FTC actual price are defined
 * differently and will diverge once the dealer pricing feed lands.
 */
class VehiclePrice extends Data
{
    /**
     * @param array<int, array{type: string, amount: float}> $mandatoryDealerFees
     */
    public function __construct(
        public readonly Variants $variant,
        public readonly string $vehicleKey,
        public readonly float $caCarsTotalPrice,
        public readonly float $ftcActualPrice,
        public readonly array $mandatoryDealerFees,
        public readonly string $sourceSystem,
        public readonly ?string $sourceVersion = null,
        public readonly string $currency = 'USD',
    ) {
    }

    public function mandatoryDealerFeesTotal(): float
    {
        return array_sum(array_map(
            fn (array $fee): float => (float) ($fee['amount'] ?? 0),
            $this->mandatoryDealerFees
        ));
    }
}
