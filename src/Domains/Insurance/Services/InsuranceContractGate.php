<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Contracts\PaymentReportProviderInterface;
use Kanvas\Insurance\Contracts\PolicyEmissionProviderInterface;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;

final class InsuranceContractGate
{
    public static function isContractable(InsuranceProviderInterface $provider): bool
    {
        return $provider instanceof PolicyEmissionProviderInterface
            && $provider instanceof PaymentReportProviderInterface;
    }

    public static function assertContractable(InsuranceProviderInterface $provider): void
    {
        if (! self::isContractable($provider)) {
            throw new InsuranceValidationException('This insurer cannot be contracted through MoviPass yet');
        }
    }
}
