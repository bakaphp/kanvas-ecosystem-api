<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * `canonical_id` is picked as the oldest id so existing inbound FKs on that row stay put.
 *
 * `reason`, in descending order of confidence: external_id_conflict (each member has its own
 * distinct third-party id — the only reason an agent may auto-merge without human approval),
 * exact_name, lastname_match (lowest confidence — also catches unrelated people sharing a
 * surname), email_match.
 */
class PeopleDuplicateGroup extends Data
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
