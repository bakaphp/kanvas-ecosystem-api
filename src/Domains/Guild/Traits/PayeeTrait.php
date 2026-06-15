<?php

declare(strict_types=1);

namespace Kanvas\Guild\Traits;

/**
 * Default implementation of `Baka\Contracts\PayeeInterface` for Guild Organization.
 *
 * Only `Organization` mixes this in — the legal vendor on Scribe transactions is always an
 * Organization (an employee that gets paid via Expense reimbursement is tracked via
 * `expense.paid_by_users_id`, not as a Payee).
 *
 * Same Organization satisfies BillableInterface + PayeeInterface — a Guild Org can be customer
 * and vendor simultaneously. The Scribe Action decides which getter set to call based on the
 * transaction's direction.
 */
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
        $raw = trim((string) ($this->address ?? ''));

        return $raw === '' ? null : ['raw' => $raw];
    }

    public function getDefaultPayableCurrency(): string
    {
        $stored = $this->get('default_currency');

        return is_string($stored) && $stored !== '' ? $stored : 'USD';
    }
}
