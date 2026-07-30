<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * One cluster of Guild Organizations the duplicate-finder thinks are the same legal entity.
 *
 * `canonical_id` is the operator's recommended target for merges — picked by oldest id so existing
 * inbound FKs (invoices, deals, etc.) on the established row stay put. Operator UI can override
 * by passing a different target to `mergeOrganizations`.
 *
 * `member_ids` is sorted ascending. Includes `canonical_id` for easier client iteration.
 *
 * `reason` is the SQL match dimension that flagged the cluster. Useful for showing the operator
 * WHY these were grouped:
 *   - exact_name        — `LOWER(TRIM(name))` matched between rows
 *   - email_match       — a People contact email is shared
 *   - tax_id_match      — same value in `tax_id` / `rnc` / `ein` custom fields
 */
class OrganizationDuplicateGroup extends Data
{
    public function __construct(
        public readonly int $canonical_id,
        /** @var list<int> */
        public readonly array $member_ids,
        public readonly string $reason,
        public readonly string $sample_name,
    ) {
    }
}
