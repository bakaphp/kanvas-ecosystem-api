<?php

declare(strict_types=1);

namespace Kanvas\Guild\Concerns;

trait IsBillable
{
    use HasBillingParty;

    public function getBillableId(): int
    {
        return (int) $this->id;
    }

    public function getBillableDisplayName(): string
    {
        return $this->partyDisplayName();
    }

    public function getBillableLegalName(): ?string
    {
        return $this->partyLegalName();
    }

    public function getBillableTaxId(): ?string
    {
        return $this->partyTaxId();
    }

    public function getBillingEmail(): ?string
    {
        return $this->partyEmail();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBillingAddressArray(): ?array
    {
        return $this->partyAddressArray();
    }

    public function getDefaultPaymentTermsDays(): int
    {
        $stored = $this->get('default_payment_terms_days');
        if ($stored !== null && is_numeric($stored)) {
            return (int) $stored;
        }

        return 30;
    }

    public function getDefaultCurrency(): string
    {
        return $this->partyDefaultCurrency();
    }
}
