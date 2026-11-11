<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

use Kanvas\Scribe\Bills\Models\Bill;

/**
 * A provider that can send DGII's commercial response (accept / reject) for a vendor e-CF we received.
 * Implemented next to EcfProviderInterface on the provider itself, so callers ask the company's provider
 * instead of branching on which one it is.
 */
interface RespondsToReceivedEcfInterface
{
    /**
     * @return string|null the provider's reference for the response, when it returns one
     */
    public function sendCommercialResponse(Bill $bill, bool $accepted, ?string $reason = null): ?string;
}
