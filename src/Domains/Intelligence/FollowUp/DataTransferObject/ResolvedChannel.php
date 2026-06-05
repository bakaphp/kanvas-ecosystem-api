<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Kanvas\Guild\Customers\Models\Contact;

/**
 * One eligible outbound channel for a follow-up — the channel type plus the
 * specific Contact row to dispatch to. `reason` is a short tag for the ledger
 * payload so we can audit why this channel/contact won the resolution.
 */
final readonly class ResolvedChannel
{
    public function __construct(
        public string $channelType,
        public Contact $contact,
        public string $reason,
    ) {
    }
}
