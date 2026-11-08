<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\RespondsToReceivedEcfInterface;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;

/**
 * DGII's commercial response to a vendor e-CF, through whichever provider the company uses. DGII takes one
 * answer per document and forwards it to the vendor, so a bill that was already answered is refused rather
 * than answered twice.
 */
class RespondToReceivedEcfAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly bool $accepted,
        public readonly ?string $reason = null,
        public readonly ?UserInterface $user = null,
        private readonly EcfProviderResolver $resolver = new EcfProviderResolver(),
    ) {
    }

    /**
     * @return array<string, mixed> the commercial response as stored on the bill's received fiscal row
     */
    public function execute(): array
    {
        $bill = $this->bill;

        if (! ReceivedEcfCompliance::isReceivedEcf($bill)) {
            throw new ValidationException("Bill {$bill->getId()} did not come in as a received e-CF; there is nothing to answer.");
        }

        if (ReceivedEcfCompliance::commercialResponse($bill) !== null) {
            throw new ValidationException(
                'e-CF ' . ReceivedEcfCompliance::encf($bill) . ' was already answered; DGII takes one commercial response per document.'
            );
        }

        $reason = Str::trimToNull($this->reason);

        if (! $this->accepted && $reason === null) {
            throw new ValidationException('Rejecting a vendor e-CF requires a reason; DGII and the vendor receive it.');
        }

        $provider = $this->resolver->resolve($bill->app, $bill->company);

        if (! $provider instanceof RespondsToReceivedEcfInterface) {
            throw new ValidationException(
                "The company's e-CF provider '{$this->resolver->providerKey($bill->company)}' cannot send commercial responses."
            );
        }

        $reference = $provider->sendCommercialResponse($bill, $this->accepted, $reason);

        // The provider may have recorded its own transport fields on the event (WebPOS does); merge on top.
        ReceivedEcfCompliance::recordCommercialResponse($bill, [
            'accepted' => $this->accepted,
            'reason' => $reason,
            'provider' => $this->resolver->providerKey($bill->company),
            'provider_ref' => $reference,
            'responded_at' => Carbon::now()->toIso8601String(),
            'responded_by_users_id' => $this->user?->getId(),
        ]);

        $bill->emitLedgerEvent('scribe.bill.ecf_commercial_response_sent', payload: [
            'bill_id' => $bill->getId(),
            'encf' => ReceivedEcfCompliance::encf($bill),
            'vendor_rnc' => ReceivedEcfCompliance::vendorTaxId($bill),
            'accepted' => $this->accepted,
            'reason' => $reason,
        ]);

        return (array) ReceivedEcfCompliance::commercialResponse($bill);
    }
}
