<?php

declare(strict_types=1);

namespace Baka\Contracts;

/**
 * A model that can be billed (the customer on an invoice or the vendor on a bill).
 *
 * Implemented by Kanvas\Guild\Organizations\Models\Organization and Kanvas\Guild\Customers\Models\People.
 * Implementation lives in Guild; Scribe consumes the interface — no Guild→Scribe dependency arrow.
 *
 * The billable snapshot frozen on issued invoices is hydrated from these getters at issue time and never
 * auto-updated (per plan §7.4 — billable snapshots frozen at issue).
 *
 * @see plan §4.1 — Guild (CRM) → Customer + Vendor on every transaction
 * @see plan §7.4 — Billable snapshots frozen at issue
 */
interface BillableInterface
{
    public function getBillableId(): int;

    /**
     * Polymorphic discriminator stored on the invoice's billable_type column.
     * Convention: lowercase short name — 'organization' for Guild Organization, 'people' for Guild People.
     */
    public function getBillableType(): string;

    public function getBillableDisplayName(): string;

    public function getBillableLegalName(): ?string;

    public function getBillableTaxId(): ?string;

    public function getBillingEmail(): ?string;

    /**
     * Address as an associative array (street/city/state/zip/country) for json-snapshot storage.
     * Null when the billable has no address on record. NOT typed to a specific Address class so Baka
     * doesn't import a Kanvas-specific class.
     */
    public function getBillingAddressArray(): ?array;

    public function getDefaultPaymentTermsDays(): int;

    public function getDefaultCurrency(): string;
}
