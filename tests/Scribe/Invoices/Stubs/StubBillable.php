<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices\Stubs;

use Baka\Contracts\BillableInterface;

/**
 * Minimal BillableInterface stub for testing Invoice actions before Guild.Organization implements it.
 * Once Guild's HasAccountingRoles trait lands, the production Organization model satisfies the contract
 * and these stubs are replaced by real Guild factory rows.
 */
class StubBillable implements BillableInterface
{
    public function __construct(
        private readonly int $id = 4711,
        private readonly string $type = 'organization',
        private readonly string $displayName = 'ACME Corp',
        private readonly ?string $legalName = 'ACME Corporation Limited',
        private readonly ?string $taxId = '123-45678-9',
        private readonly ?string $email = 'ap@acme.do',
        private readonly ?array $address = null,
        private readonly int $defaultPaymentTermsDays = 30,
        private readonly string $defaultCurrency = 'USD',
    ) {
    }

    public function getBillableId(): int
    {
        return $this->id;
    }

    public function getBillableType(): string
    {
        return $this->type;
    }

    public function getBillableDisplayName(): string
    {
        return $this->displayName;
    }

    public function getBillableLegalName(): ?string
    {
        return $this->legalName;
    }

    public function getBillableTaxId(): ?string
    {
        return $this->taxId;
    }

    public function getBillingEmail(): ?string
    {
        return $this->email;
    }

    public function getBillingAddressArray(): ?array
    {
        return $this->address ?? [
            'street' => '101 Main St',
            'city' => 'Santo Domingo',
            'country' => 'DO',
            'postal_code' => '10101',
        ];
    }

    public function getDefaultPaymentTermsDays(): int
    {
        return $this->defaultPaymentTermsDays;
    }

    public function getDefaultCurrency(): string
    {
        return $this->defaultCurrency;
    }
}
