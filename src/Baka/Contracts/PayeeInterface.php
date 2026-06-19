<?php

declare(strict_types=1);

namespace Baka\Contracts;

/**
 * A model that can be paid (the vendor on a bill or expense — parallel to BillableInterface).
 *
 * Implemented by Kanvas\Guild\Organizations\Models\Organization ONLY — the legal vendor on a Scribe
 * transaction is always an Organization. The same Org satisfies both BillableInterface + PayeeInterface
 * (one Guild Org can be customer AND vendor at the same time). Implementation lives in Guild; Scribe
 * consumes the interface with no Guild→Scribe dependency arrow.
 *
 * @see plan §4.1 — Guild (CRM) → Customer + Vendor on every transaction
 */
interface PayeeInterface
{
    public function getPayeeId(): int;

    public function getPayeeDisplayName(): string;

    public function getPayeeLegalName(): ?string;

    public function getPayeeTaxId(): ?string;

    public function getPayeeEmail(): ?string;

    public function getPayeeAddressArray(): ?array;

    public function getDefaultPayableCurrency(): string;
}
