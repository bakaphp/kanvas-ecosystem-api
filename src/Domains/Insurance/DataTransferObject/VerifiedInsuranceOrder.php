<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Insurance\Models\InsurancePlan;

class VerifiedInsuranceOrder
{
    public const float PRICE_TOLERANCE = 0.005;

    public function __construct(
        public readonly InsurancePlan $plan,
        public readonly InsuranceProviderInterface $provider,
        public readonly float $planPrice,
    ) {
    }

    public function assertPriceMatches(QuoteResult $quote): void
    {
        if (abs((float) $quote->total - $this->planPrice) > self::PRICE_TOLERANCE) {
            throw new InsuranceValidationException('The price of the plan changed, please search again');
        }
    }
}
