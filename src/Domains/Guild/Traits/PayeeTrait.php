<?php

declare(strict_types=1);

namespace Kanvas\Guild\Traits;

trait PayeeTrait
{
    public function getPayeeId(): int
    {
        return (int) $this->id;
    }

    public function getPayeeDisplayName(): string
    {
        return (string) ($this->name ?? '');
    }

    public function getPayeeLegalName(): ?string
    {
        return (string) ($this->name ?? null) ?: null;
    }

    public function getPayeeTaxId(): ?string
    {
        return $this->get('tax_id')
            ?? $this->get('rnc')
            ?? $this->get('ein');
    }

    public function getPayeeEmail(): ?string
    {
        if (! method_exists($this, 'peoples')) {
            return null;
        }
        $primary = $this->peoples()->first();
        if ($primary !== null && method_exists($primary, 'getEmails')) {
            return $primary->getEmails()->first()?->value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayeeAddressArray(): ?array
    {
        // Vendor address = the Org's billing address. Same chain as BillableTrait: walk to
        // People::getBillingAddressArray() (typed `peoples_address` row), fall back to the
        // Organization's free-text column.
        if (method_exists($this, 'peoples')) {
            $primary = $this->peoples()->first();
            $address = $primary?->getBillingAddressArray();
            if ($address !== null) {
                return $address;
            }
        }

        $line = trim((string) ($this->address ?? ''));

        return $line === '' ? null : ['address' => $line];
    }

    public function getDefaultPayableCurrency(): string
    {
        $stored = $this->get('default_currency');

        return is_string($stored) && $stored !== '' ? $stored : 'USD';
    }
}
