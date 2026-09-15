<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * A drafted customer update, before anyone has approved or sent it.
 *
 * `releaseTags` travels with the body so the approver can see which releases the draft claims to cover
 * before approving, and so the watermark advances to what was actually read rather than to "now".
 */
class CustomerUpdateDraft
{
    /**
     * @param array<int, string> $releaseTags
     */
    public function __construct(
        public readonly Organization $organization,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?Carbon $coveredFrom,
        public readonly ?Carbon $coveredThrough,
        public readonly array $releaseTags = [],
    ) {
    }
}
