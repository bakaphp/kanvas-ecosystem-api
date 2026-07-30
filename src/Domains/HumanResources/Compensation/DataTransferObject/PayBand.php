<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Compensation\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Positions\Models\Position;
use Spatie\LaravelData\Data;

class PayBand extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly float $minAmount,
        public readonly float $maxAmount,
        public readonly string $effectiveFrom,
        public readonly ?Position $position = null,
        public readonly ?string $name = null,
        public readonly ?string $level = null,
        public readonly string $currency = 'USD',
        public readonly string $payFrequency = 'annual',
        public readonly ?float $midAmount = null,
    ) {
    }
}
