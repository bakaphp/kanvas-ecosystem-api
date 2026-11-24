<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\DataTransferObject;

use Illuminate\Support\Carbon;

/**
 * $rate is one unit of $source in $target.
 */
final class MarketRate
{
    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly float $rate,
        public readonly Carbon $observedAt,
        public readonly string $provider,
    ) {
    }
}
