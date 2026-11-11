<?php

declare(strict_types=1);

namespace Kanvas\Guild\Concerns;

trait IsPayee
{
    use HasBillingParty;

    public function getPayeeId(): int
    {
        return (int) $this->id;
    }

    public function getPayeeDisplayName(): string
    {
        return $this->partyDisplayName();
    }

    public function getPayeeLegalName(): ?string
    {
        return $this->partyLegalName();
    }

    public function getPayeeTaxId(): ?string
    {
        return $this->partyTaxId();
    }

    public function getPayeeEmail(): ?string
    {
        return $this->partyEmail();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayeeAddressArray(): ?array
    {
        return $this->partyAddressArray();
    }

    public function getDefaultPayableCurrency(): string
    {
        return $this->partyDefaultCurrency();
    }
}
