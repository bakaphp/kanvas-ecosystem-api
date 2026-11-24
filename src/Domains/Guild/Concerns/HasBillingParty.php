<?php

declare(strict_types=1);

namespace Kanvas\Guild\Concerns;

use Baka\Support\Str;

/**
 * What an Organization looks like as the other side of a document, whichever side it is on: IsBillable
 * (customer) and IsPayee (vendor) are the same party read through two interfaces.
 */
trait HasBillingParty
{
    protected function partyTaxId(): ?string
    {
        return Str::trimToNull($this->tax_id ?? null);
    }

    protected function partyDisplayName(): string
    {
        return (string) ($this->name ?? '');
    }

    protected function partyLegalName(): ?string
    {
        return Str::trimToNull($this->name ?? null);
    }

    /**
     * Organizations have no email column, so the primary person's email is the delivery destination.
     */
    protected function partyEmail(): ?string
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
     * The primary person's typed billing address, else the Organization's free-text column.
     *
     * @return array<string, mixed>|null
     */
    protected function partyAddressArray(): ?array
    {
        if (method_exists($this, 'peoples')) {
            $address = $this->peoples()->first()?->getBillingAddressArray();
            if ($address !== null) {
                return $address;
            }
        }

        $line = trim((string) ($this->address ?? ''));

        return $line === '' ? null : ['address' => $line];
    }

    protected function partyDefaultCurrency(): string
    {
        $stored = $this->get('default_currency');

        return is_string($stored) && $stored !== '' ? $stored : 'USD';
    }
}
