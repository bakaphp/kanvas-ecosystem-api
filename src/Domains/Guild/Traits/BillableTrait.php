<?php

declare(strict_types=1);

namespace Kanvas\Guild\Traits;

/**
 * Default implementation of `Baka\Contracts\BillableInterface` for Guild Organization.
 *
 * Only `Organization` mixes this in — People is not a customer. The legal counterparty on every
 * Scribe transaction is always an Organization; per-person addressing on Quote happens via the
 * separate `contact_people_id` field.
 *
 * Field mapping is defensive: most accessors fall back to null when the underlying Guild schema
 * doesn't carry the field today (tax id, structured address, etc.). Snapshot-on-issue means
 * downstream Scribe rows still get good data — operators fill the gaps once and the snapshot is
 * frozen on the invoice.
 */
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
        return $this->customFieldValue('tax_id')
            ?? $this->customFieldValue('rnc')
            ?? $this->customFieldValue('ein');
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
        // Organization keeps a free-text `address` column for now — return it as a single-field array
        // so downstream code can render it without further schema work. When Guild adopts a
        // structured Org-address model, swap this out.
        $raw = trim((string) ($this->address ?? ''));

        return $raw === '' ? null : ['raw' => $raw];
    }

    public function getDefaultPaymentTermsDays(): int
    {
        $stored = $this->customFieldValue('default_payment_terms_days');
        if ($stored !== null && is_numeric($stored)) {
            return (int) $stored;
        }

        return 30;
    }

    public function getDefaultCurrency(): string
    {
        $stored = $this->customFieldValue('default_currency');

        return is_string($stored) && $stored !== '' ? $stored : 'USD';
    }

    /**
     * Best-effort custom field read — uses `getCustomField` when present, returns null otherwise.
     * Stays defensive so the trait doesn't fatal on models without the CustomFields trait wired.
     */
    private function customFieldValue(string $key): ?string
    {
        if (! method_exists($this, 'getCustomField')) {
            return null;
        }

        $value = $this->getCustomField($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
