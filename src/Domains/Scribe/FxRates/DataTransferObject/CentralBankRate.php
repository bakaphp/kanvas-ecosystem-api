<?php

declare(strict_types=1);

namespace Kanvas\Scribe\FxRates\DataTransferObject;

use Illuminate\Support\Carbon;

final class CentralBankRate
{
    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly string $rate,
        public readonly Carbon $rateDate,
        public readonly string $provider,
        public readonly string $type,
    ) {
    }
}
