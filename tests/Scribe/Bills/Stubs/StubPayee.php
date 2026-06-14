<?php

declare(strict_types=1);

namespace Tests\Scribe\Bills\Stubs;

use Baka\Contracts\PayeeInterface;

class StubPayee implements PayeeInterface
{
    public function __construct(
        private readonly int $id = 9911,
        private readonly string $type = 'organization',
        private readonly string $displayName = 'Mercury Bank',
        private readonly ?string $legalName = 'Mercury Bank Inc.',
        private readonly ?string $taxId = '12-3456789',
        private readonly ?string $email = 'ap@mercury.test',
        private readonly ?array $address = null,
        private readonly string $defaultCurrency = 'USD',
    ) {
    }

    public function getPayeeId(): int
    {
        return $this->id;
    }

    public function getPayeeType(): string
    {
        return $this->type;
    }

    public function getPayeeDisplayName(): string
    {
        return $this->displayName;
    }

    public function getPayeeLegalName(): ?string
    {
        return $this->legalName;
    }

    public function getPayeeTaxId(): ?string
    {
        return $this->taxId;
    }

    public function getPayeeEmail(): ?string
    {
        return $this->email;
    }

    public function getPayeeAddressArray(): ?array
    {
        return $this->address ?? ['street' => '101 SoMa', 'city' => 'San Francisco', 'country' => 'US'];
    }

    public function getDefaultPayableCurrency(): string
    {
        return $this->defaultCurrency;
    }
}
