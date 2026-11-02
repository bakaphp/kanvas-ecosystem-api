<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Kanvas\Insurance\DataTransferObject\QuoteResult;

class InsuranceCommissionCalculator
{
    public static function effective(QuoteResult $quote, float $planCommissionRate): float
    {
        if ($quote->commission !== null) {
            return $quote->commission;
        }

        if ($quote->premium === null || $planCommissionRate <= 0) {
            return 0.0;
        }

        return round($quote->premium * $planCommissionRate / 100, 2);
    }
}
