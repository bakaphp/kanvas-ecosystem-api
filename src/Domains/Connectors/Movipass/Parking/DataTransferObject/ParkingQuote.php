<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\DataTransferObject;

use Illuminate\Support\Carbon;

final readonly class ParkingQuote
{
    public function __construct(
        public float $amount,
        public string $reference,
        public Carbon $quotedAt,
    ) {
    }
}
