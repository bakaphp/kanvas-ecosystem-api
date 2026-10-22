<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;

interface VehicleReferenceQuoteProviderInterface extends CanonicalQuoteProviderInterface
{
    public function canQuoteVehicle(CanonicalQuoteInput $input, string $planCode): bool;

    public function hasRequiredInformation(CanonicalQuoteInput $input, string $planCode): bool;
}
