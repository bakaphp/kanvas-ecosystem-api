<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Kanvas\Guild\Customers\Models\Contact;
use Spatie\LaravelData\Data;

class ResolvedChannel extends Data
{
    public function __construct(
        public readonly string $channelType,
        public readonly Contact $contact,
        public readonly string $reason,
    ) {
    }
}
