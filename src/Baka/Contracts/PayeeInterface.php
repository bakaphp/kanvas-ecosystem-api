<?php

declare(strict_types=1);

namespace Baka\Contracts;

/**
 * A model that can be paid (the vendor on a bill or expense — parallel to BillableInterface).
 *
 * Implemented by Kanvas\Guild\Organizations\Models\Organization and Kanvas\Guild\Customers\Models\People
 * (the same Guild models also implement BillableInterface — one Guild Org can be both customer and vendor).
 * Implementation lives in Guild; Scribe consumes the interface — no Guild→Scribe dependency arrow.
 *
 * @see plan §4.1 — Guild (CRM) → Customer + Vendor on every transaction
 */
interface PayeeInterface
{
    public function getPayeeId(): int;

    /**
     * Polymorphic discriminator stored on the bill/expense's vendor_billable_type column.
     * Convention: lowercase short name — 'organization' for Guild Organization, 'people' for Guild People.
     */
    public function getPayeeType(): string;

    public function getPayeeDisplayName(): string;

    public function getPayeeLegalName(): ?string;

    public function getPayeeTaxId(): ?string;

    public function getPayeeEmail(): ?string;

    public function getPayeeAddressArray(): ?array;

    public function getDefaultPayableCurrency(): string;
}
