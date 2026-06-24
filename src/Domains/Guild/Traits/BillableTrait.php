<?php

declare(strict_types=1);

namespace Kanvas\Guild\Traits;

trait BillableTrait
{
    public function getBillableId(): int
    {
        return (int) $this->id;
    }

    public function getBillableDisplayName(): string
    {
        return (string) ($this->name ?? '');
    }

    public function getBillableLegalName(): ?string
    {
        return (string) ($this->name ?? null) ?: null;
    }

    public function getBillableTaxId(): ?string
    {
        return $this->get('tax_id')
            ?? $this->get('rnc')
            ?? $this->get('ein');
    }

    public function getBillingEmail(): ?string
    {
        // Organizations don't have a direct email column. Surface the primary person's email when
        // available so invoices have a delivery destination by default. The `peoples` relation lives
        // on Organization — guard for non-Org consumers of the trait.
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
    public function getBillingAddressArray(): ?array
    {
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
        $stored = $this->get('default_currency');

        return is_string($stored) && $stored !== '' ? $stored : 'USD';
    }
}
