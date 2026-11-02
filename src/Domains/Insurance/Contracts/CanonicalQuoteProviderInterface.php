<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\QuoteResult;

interface CanonicalQuoteProviderInterface extends InsuranceProviderInterface
{
    public function requiresVehicleModelId(): bool;

    public function quoteCanonical(
        CanonicalQuoteInput $input,
        string $planCode,
        ?string $vehicleModelId
    ): QuoteResult;
}
